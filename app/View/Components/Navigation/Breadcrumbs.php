<?php

namespace App\View\Components\Navigation;

use Closure;
use App\Models\Page;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Carbon;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class Breadcrumbs extends Component
{
    /** @var array<array{title:string,url:?string}> */
    public array $items = [];

    public ?string $updated = null;

    public ?string $schemaBreadcrumbsJsonLd = null;

    public function __construct(
        public bool $home = true,
        public string $homeTitle = 'Главная',
        public ?int $ttl = 3600
    ) {
        $this->items = $this->detect();

        $lastUpdated = Product::max('updated_at');
        $this->updated = $lastUpdated
            ? Carbon::parse($lastUpdated)->format('d.m.Y')
            : null;

        $this->schemaBreadcrumbsJsonLd = $this->buildSchemaBreadcrumbsJsonLd();
    }

    protected function buildSchemaBreadcrumbsJsonLd(): ?string
    {
        $list = [];
        $position = 1;

        if ($this->home) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $this->homeTitle,
                'item' => url('/'),
            ];
        }

        foreach ($this->items as $bc) {
            $name = (string) ($bc['title'] ?? '');
            if ($name === '') {
                continue;
            }

            $itemUrl = $bc['url'] ?? null;
            if ($itemUrl === null) {
                $itemUrl = url()->current();
            }

            $list[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
                'item' => $itemUrl,
            ];
        }

        if (count($list) < 2) {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function render(): View|Closure|string
    {
        return view('components.navigation.breadcrumbs');
    }

    /**
     * Сбор крошек по текущему роуту.
     * Ничего прокидывать в layout не нужно.
     */
    protected function detect(): array
    {
        $route = Route::current();
        if (! $route) {
            return [];
        }

        $name = (string) $route->getName();
        $params = $route->parameters();

        if ($name === 'catalog.leaf') {
            $path = (string) ($params['path'] ?? '');
            return $path !== '' ? $this->fromCategoryPath($path) : [];
        }

        if ($name === 'product.show') {
            if (isset($params['product']) && $params['product'] instanceof Product) {
                $category = $params['product']->primaryCategory();
                if ($category instanceof Category) {
                    return $this->fromCategory($category, makeLastLink: true);
                }
            }
        }

        if ($name === 'page.show') {
            if (isset($params['page']) && $params['page'] instanceof Page) {
                return [
                    ['title' => (string) $params['page']->title, 'url' => null],
                ];
            }
        }

        return [];
    }

    /** Собираем крошки по Category-цепочке */
    protected function fromCategory(Category $category, bool $makeLastLink = false): array
    {
        $key = "breadcrumbs:cat:{$category->getKey()}:linklast:" . ($makeLastLink ? '1' : '0');

        return Cache::remember($key, $this->ttl, function () use ($category, $makeLastLink) {
            $chain = $category->ancestorsAndSelf();
            $path = '';

            $items = $chain->map(function ($c) use (&$path) {
                $slug = (string) ($c->slug ?? '');
                $path = ltrim($path . '/' . $slug, '/');
                return [
                    'title' => (string) $c->name,
                    'url' => route('catalog.leaf', ['path' => $path]),
                ];
            })->values();

            if (! $makeLastLink && $items->isNotEmpty()) {
                $last = $items->pop();
                $last['url'] = null;
                $items->push($last);
            }

            return $items->all();
        });
    }

    /**
     * Восстанавливаем Category-цепочку из {path}: "a/b/c".
     */
    protected function fromCategoryPath(string $path): array
    {
        $path = trim($path, '/');
        $segments = $path === '' ? [] : explode('/', $path);

        if (empty($segments)) {
            return [];
        }

        $cacheKey = 'breadcrumbs:path:' . md5($path);

        return Cache::remember($cacheKey, $this->ttl, function () use ($segments) {
            $items = [];
            $accum = '';
            $parentId = Category::defaultParentKey();

            foreach ($segments as $seg) {
                $accum = ltrim($accum . '/' . $seg, '/');

                $cat = Category::query()
                    ->where('parent_id', $parentId)
                    ->where('slug', $seg)
                    ->first();

                if (! $cat) {
                    break;
                }

                $items[] = [
                    'title' => (string) $cat->name,
                    'url' => route('catalog.leaf', ['path' => $accum]),
                ];

                $parentId = $cat->getKey();
            }

            if (! empty($items)) {
                $items[count($items) - 1]['url'] = null;
            }

            return $items;
        });
    }
}
