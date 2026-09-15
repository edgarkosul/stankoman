<?php

namespace App\Shop;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Services\Ai\Contracts\ProductLookup;
use App\Services\Chat\Contracts\PageContextSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 */
final class ShopPageContext implements PageContextSource
{
    public function __construct(
        private readonly ProductLookup $products,
    ) {}

    public function locate(Request $request): ?array
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        return match ($route->getName()) {
            'home' => ['type' => 'home'],
            'product.show' => ['type' => 'product', 'slug' => self::slug($route->parameter('product'))],
            'catalog.leaf' => self::catalog((string) $route->parameter('path')),
            'page.show' => ['type' => 'page', 'slug' => self::slug($route->parameter('page'))],
            default => null,
        };
    }

    public function describe(?array $locator, bool $seesDiscounts): ?array
    {
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

        $card = $this->products->find('', $slug, $seesDiscounts);

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
