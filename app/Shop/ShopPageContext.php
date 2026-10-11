<?php

namespace App\Shop;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Siteko\Chatbot\Ai\Contracts\ProductLookup;
use Siteko\Chatbot\Ai\Tools\ToolContext;
use Siteko\Chatbot\Chat\Contracts\PageContextSource;
use Throwable;

/**
 * Страница витрины, на которой стоит посетитель, глазами чата.
 *
 * Товар разворачивается через ProductLookup, то есть той же карточкой, что
 * видит инструмент бота: цена в контексте страницы — ровно то число, что
 * крупно стоит на карточке у этого посетителя, с той же припиской про
 * скидку и НДС. У донора здесь была своя формула цены, вторая копия
 * ценовой политики, — а у нас скидка видна по посетителю, и разойтись
 * две копии могли бы ровно в деньгах.
 *
 * Панель чата пакета живёт в своей рамке (`/chat/panel`) и о странице знает
 * только то, что ей сказал лаунчер: `{type: page, url, title}`. Что это за
 * страница, решают маршруты самого сайта (`locator()`): адрес карточки
 * товара — тот же разбор, что у роутера, а не своё регулярное выражение.
 * Не узнали — остаётся адрес с заголовком, это тоже контекст.
 */
final class ShopPageContext implements PageContextSource
{
    public function __construct(
        private readonly ProductLookup $products,
        private readonly Router $router,
    ) {}

    public function locate(Request $request): ?array
    {
        $route = $request->route();

        return $route instanceof Route ? self::fromRoute($route) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes  кто спрашивает — `ToolContext::$attributes`
     */
    public function describe(?array $locator, array $attributes = []): ?array
    {
        if ($locator === null || $locator === []) {
            return null;
        }

        if (isset($locator['url']) && ! isset($locator['slug']) && ! isset($locator['path'])) {
            $resolved = $this->locator((string) $locator['url']);

            // Не наша страница или её нет — отдаём то, что прислала панель.
            if ($resolved === null) {
                return $locator;
            }

            return $this->resolve($resolved, $attributes) ?? $locator;
        }

        return $this->resolve($locator, $attributes);
    }

    /**
     * Адрес страницы → указатель вида `{type, slug|path}` по маршрутам сайта.
     *
     * @return array<string, string>|null
     */
    private function locator(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $route = $this->router->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return null;
        }

        return self::fromRoute($route);
    }

    /**
     * @return array<string, string>|null
     */
    private static function fromRoute(Route $route): ?array
    {
        return match ($route->getName()) {
            'home' => ['type' => 'home'],
            'product.show' => ['type' => 'product', 'slug' => self::slug($route->parameter('product'))],
            'catalog.leaf' => self::catalog((string) $route->parameter('path')),
            'page.show' => ['type' => 'page', 'slug' => self::slug($route->parameter('page'))],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $locator
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function resolve(array $locator, array $attributes): ?array
    {
        $seesDiscounts = (bool) ($attributes[ToolContext::SEES_MEMBER_PRICES] ?? false);
        $type = $locator['type'] ?? null;

        if ($type === null) {
            return null;
        }

        try {
            return match ($type) {
                'home' => ['type' => 'home'],
                'catalog' => ['type' => 'catalog'],
                'product' => $this->product((string) ($locator['slug'] ?? ''), $seesDiscounts),
                'category' => $this->category((string) ($locator['path'] ?? '')),
                'page' => $this->page((string) ($locator['slug'] ?? '')),
                default => null,
            };
        } catch (Throwable $e) {
            Log::debug('Chat page context unresolved', ['locator' => $locator, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function product(string $slug, bool $seesDiscounts): ?array
    {
        /*
         * Сначала — есть ли активный товар ровно с этим адресом. Без проверки
         * снятый с продажи товар ушёл бы во второй заход ProductLookup::find()
         * по обозначению модели, и слаг вида «kompressor-vk-11» мог бы найти
         * другую машину: бот заговорил бы о товаре, которого на экране нет.
         */
        if ($slug === '' || ! Product::query()->where('slug', $slug)->where('is_active', true)->exists()) {
            return null;
        }

        $card = $this->products->find('', $slug, [ToolContext::SEES_MEMBER_PRICES => $seesDiscounts]);

        if ($card === null) {
            return null;
        }

        return [
            'type' => 'product',
            'id' => $card->id,
            'slug' => $slug,
            'name' => Str::limit($card->name, 200, ''),
            'sku' => $card->sku,
            'price_label' => $card->priceLine(),
            'in_stock' => $card->inStock,
            'availability' => $card->availabilityLine(),
            'url' => $card->url,
        ];
    }

    /**
     * Путь разбирается так же, как его разбирает сама страница раздела:
     * по цепочке родителей, только активные и не из «отстойника» импорта.
     *
     * @return array<string, mixed>|null
     */
    private function category(string $path): ?array
    {
        $slugs = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $slug): bool => $slug !== ''));

        if ($slugs === []) {
            return null;
        }

        $parentId = Category::defaultParentKey();
        $category = null;

        foreach ($slugs as $slug) {
            $category = Category::query()
                ->active()
                ->withoutStaging()
                ->where('parent_id', $parentId)
                ->where('slug', $slug)
                ->first();

            if ($category === null) {
                return null;
            }

            $parentId = $category->getKey();
        }

        return [
            'type' => 'category',
            'id' => $category->getKey(),
            'name' => (string) $category->name,
            'path' => implode('/', $slugs),
            'breadcrumb' => $category->ancestorsAndSelf()->pluck('name')->filter()->implode(' → '),
            'url' => route('catalog.leaf', ['path' => implode('/', $slugs)]),
        ];
    }

    /**
     * Только опубликованные: иначе бот назвал бы страницу, которая отвечает 404.
     *
     * @return array<string, mixed>|null
     */
    private function page(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $page = Page::query()->where('slug', $slug)->where('is_published', true)->first();

        if ($page === null) {
            return null;
        }

        return [
            'type' => 'page',
            'slug' => $slug,
            'title' => Str::limit((string) $page->title, 200, ''),
            'url' => route('page.show', ['page' => $slug]),
        ];
    }

    /**
     * Корень каталога — не раздел: разворачивать там нечего, но сказать
     * модели, что посетитель смотрит каталог, стоит.
     *
     * @return array<string, string>
     */
    private static function catalog(string $path): array
    {
        $path = trim($path, '/');

        return $path === '' ? ['type' => 'catalog'] : ['type' => 'category', 'path' => $path];
    }

    /**
     * Параметр маршрута приходит либо связанной моделью (штатный путь через
     * `{product:slug}`), либо строкой — если привязка ещё не отработала.
     */
    private static function slug(mixed $parameter): string
    {
        return $parameter instanceof Model
            ? (string) $parameter->getAttribute('slug')
            : (string) $parameter;
    }
}
