<?php

namespace App\Livewire\Pages\Categories;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductFilterService;
use Livewire\Attributes\Url;
use Livewire\Component;

class LeafCategoryPage extends Component
{
    public string $path = '';

    #[Url(as: 'q')]
    public ?string $q = null;

    #[Url(as: 'f')]
    public array $filters = [];

    #[Url(as: 'sort')]
    public string $sort = 'popular';

    public ?Category $category = null;

    /** Схема фильтров для UI */
    public array $filtersSchema = [];

    public int $perPage = 24;

    public int $limit = 24;

    public bool $hasMoreProducts = true;

    public function mount(?string $path = null): void
    {
        $this->path = trim($path ?? '', '/');

        if ($this->path === '') {
            return;
        }

        $this->category = $this->resolveCategoryFromPath($this->path);
        $this->filtersSchemaInit(false);
        $this->limit = $this->perPage;
    }

    protected function filtersSchemaInit(bool $wipeFilters = false): void
    {
        if ($wipeFilters) {
            $this->filters = [];
        }

        $this->filtersSchema = [];

        if ($this->category && ! $this->category->children()->exists()) {
            $schema = ProductFilterService::schemaForCategory($this->category)
                ->map
                ->toArray()
                ->values()
                ->all();

            $this->filtersSchema = $schema;

            $validKeys = array_column($schema, 'key');
            $this->filters = array_intersect_key(
                $this->filters,
                array_fill_keys($validKeys, true),
            );

            foreach ($this->filtersSchema as $f) {
                if (($f['type'] ?? null) === 'multiselect') {
                    $k = $f['key'];
                    $vals = data_get($this->filters, "{$k}.values", []);
                    if (! is_array($vals)) {
                        $vals = is_string($vals) ? explode(',', $vals) : (array) $vals;
                    }
                    data_set($this->filters, "{$k}.values", $vals);
                }
            }
        }
    }

    public function getSelectedProperty(): array
    {
        return $this->normalizeSelected($this->filtersSchema, $this->filters);
    }

    public function updatedQ(): void
    {
        $this->resetPageAndScrollToProducts();
    }

    public function updatedFilters(): void
    {
        $this->resetPageAndScrollToProducts();
    }

    public function updatedSort(): void
    {
        $this->resetPageAndScrollToProducts();
    }

    public function clearFilter(string $key): void
    {
        $schemaByKey = collect($this->filtersSchema)->keyBy('key');
        $type = $schemaByKey[$key]['type'] ?? null;

        unset($this->filters[$key]);

        $this->resetPageAndScrollToProducts();

        if ($type === 'range') {
            $this->dispatch('filter-cleared', key: $key);
        }
    }

    public function clearAll(): void
    {
        $this->filtersSchemaInit(true);

        $this->resetPageAndScrollToProducts();

        $this->dispatch('filters-cleared');
    }

    public function removeFilterValue(string $key, string $raw): void
    {
        $item = $this->filters[$key] ?? null;
        if (! $item) {
            return;
        }

        $schemaByKey = collect($this->filtersSchema)->keyBy('key');
        $typeFromSchema = $schemaByKey[$key]['type'] ?? null;
        $isMulti = ($item['type'] ?? null) === 'multiselect'
            || $typeFromSchema === 'multiselect'
            || array_key_exists('values', (array) $item);

        if (! $isMulti) {
            return;
        }

        $vals = $item['values'] ?? [];
        if (! is_array($vals)) {
            $vals = is_string($vals) ? explode(',', $vals) : (array) $vals;
        }

        $raw = (string) $raw;
        $vals = array_values(array_filter($vals, fn ($v) => (string) $v !== $raw));

        if ($vals) {
            if (isset($this->filters[$key]['type'])) {
                $this->filters[$key]['values'] = $vals;
            } else {
                $this->filters[$key] = ['values' => $vals];
            }
        } else {
            unset($this->filters[$key]);
        }

        $this->resetPageAndScrollToProducts();
    }

    private function normalizeSelected(array $schema, array $raw): array
    {
        $byKey = collect($schema)->keyBy('key');
        $out = [];

        foreach ($raw as $key => $payload) {
            $f = $byKey->get($key);
            if (! $f) {
                continue;
            }

            $type = $f['type'] ?? null;
            $cast = $f['value_cast'] ?? 'int';

            if (is_array($payload) && isset($payload['type'])) {
                $out[$key] = $payload;

                continue;
            }

            switch ($type) {
                case 'text':
                case 'number':
                    $val = is_array($payload) ? ($payload['value'] ?? null) : $payload;
                    if ($val === null || $val === '') {
                        break;
                    }
                    $out[$key] = [
                        'type' => $type,
                        'value' => $type === 'number' ? (float) $val : (string) $val,
                    ];
                    break;

                case 'select':
                    $val = is_array($payload) ? ($payload['value'] ?? null) : $payload;
                    if ($val === null || $val === '') {
                        break;
                    }
                    $val = ($cast === 'int') ? (int) $val : (string) $val;
                    $out[$key] = ['type' => 'select', 'value' => $val];
                    break;

                case 'multiselect':
                    if (is_array($payload)) {
                        $vals = $payload['values'] ?? $payload;
                        if (! is_array($vals)) {
                            $vals = is_string($vals) ? explode(',', $vals) : (array) $vals;
                        }
                    } elseif (is_string($payload)) {
                        $vals = ($cast === 'int' && str_contains($payload, ','))
                            ? explode(',', $payload)
                            : [$payload];
                    } else {
                        $vals = [];
                    }

                    $vals = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $vals);
                    $vals = array_filter($vals, fn ($v) => $v !== null && $v !== '');

                    if ($cast === 'int') {
                        $vals = array_map('intval', $vals);
                    } else {
                        $vals = array_map('strval', $vals);
                    }

                    $vals = array_values(array_unique($vals));

                    if ($vals) {
                        $out[$key] = ['type' => 'multiselect', 'values' => $vals];
                    }
                    break;

                case 'boolean':
                    $val = is_array($payload) ? ($payload['value'] ?? null) : $payload;
                    if ($val === null || $val === '') {
                        break;
                    }
                    $bool = in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes', 'да'], true);
                    $out[$key] = ['type' => 'boolean', 'value' => $bool];
                    break;

                case 'range':
                    if (is_string($payload) && str_contains($payload, ';')) {
                        [$min, $max] = explode(';', $payload, 2);
                    } else {
                        $min = is_array($payload) ? ($payload['min'] ?? null) : null;
                        $max = is_array($payload) ? ($payload['max'] ?? null) : null;
                    }

                    $min = ($min === '' ? null : ($min !== null ? (float) $min : null));
                    $max = ($max === '' ? null : ($max !== null ? (float) $max : null));

                    if ($min === null && $max === null) {
                        break;
                    }
                    if ($min !== null && $max !== null && $min > $max) {
                        [$min, $max] = [$max, $min];
                    }

                    $out[$key] = ['type' => 'range', 'min' => $min, 'max' => $max];
                    break;

                default:
                    break;
            }
        }

        return $out;
    }

    public function getActiveFiltersProperty(): array
    {
        $schemaByKey = collect($this->filtersSchema)->keyBy('key');
        $chips = [];

        foreach ($this->selected as $key => $payload) {
            $f = $schemaByKey[$key] ?? null;
            if (! $f) {
                continue;
            }

            $label = $f['label'] ?? $key;
            $type = $payload['type'];
            $suffix = $f['meta']['suffix'] ?? '';

            if ($type === 'select') {
                $val = $payload['value'] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }

                $display = $this->optionLabel($key, (string) $val) ?? (string) $val;
                $chips[] = [
                    'key' => $key,
                    'label' => $label,
                    'display' => $display,
                    'action' => 'all',
                ];
            }

            if ($type === 'multiselect') {
                $vals = $payload['values'] ?? [];
                foreach ($vals as $raw) {
                    $display = $this->optionLabel($key, (string) $raw) ?? (string) $raw;
                    $chips[] = [
                        'key' => $key,
                        'label' => $label,
                        'display' => $display,
                        'raw' => (string) $raw,
                        'action' => 'value',
                    ];
                }
            }

            if ($type === 'range') {
                $min = $payload['min'] ?? null;
                $max = $payload['max'] ?? null;
                if ($min === null && $max === null) {
                    continue;
                }

                $minTxt = $min !== null ? $this->formatNumberForChip((float) $min, $f) : '...';
                $maxTxt = $max !== null ? $this->formatNumberForChip((float) $max, $f) : '...';
                $spaceSuffix = $suffix ? ' '.$suffix : '';
                $display = trim($minTxt.' - '.$maxTxt.$spaceSuffix);

                $chips[] = [
                    'key' => $key,
                    'label' => $label,
                    'display' => $display,
                    'action' => 'all',
                ];
            }

            if ($type === 'boolean') {
                $val = (bool) ($payload['value'] ?? false);
                if (! $val) {
                    continue;
                }
                $chips[] = [
                    'key' => $key,
                    'label' => $label,
                    'display' => 'Да',
                    'action' => 'all',
                ];
            }

            if ($type === 'text') {
                $val = $payload['value'] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $display = trim((string) $val.($suffix ? ' '.$suffix : ''));
                $chips[] = [
                    'key' => $key,
                    'label' => $label,
                    'display' => $display,
                    'action' => 'all',
                ];
            }

            if ($type === 'number') {
                $val = $payload['value'] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $numStr = $this->formatNumberForChip((float) $val, $f);
                $spaceSuffix = $suffix ? ' '.$suffix : '';
                $chips[] = [
                    'key' => $key,
                    'label' => $label,
                    'display' => $numStr.$spaceSuffix,
                    'action' => 'all',
                ];
            }
        }

        return $chips;
    }

    protected array $optionLabelMap = [];

    protected function optionLabel(string $key, string $id): ?string
    {
        if (! isset($this->optionLabelMap[$key])) {
            $schemaByKey = collect($this->filtersSchema)->keyBy('key');
            $f = $schemaByKey[$key] ?? null;
            $opts = is_array($f) ? ($f['meta']['options'] ?? []) : [];
            $this->optionLabelMap[$key] = collect($opts)
                ->mapWithKeys(fn ($o) => [(string) ($o['v'] ?? '') => (string) ($o['l'] ?? '')])
                ->all();
        }

        return $this->optionLabelMap[$key][$id] ?? null;
    }

    private function resetPageAndScrollToProducts(): void
    {
        $this->limit = $this->perPage;
        $this->dispatch('category:scrollToProducts');
    }

    public function loadMore(): void
    {
        if (! $this->hasMoreProducts) {
            return;
        }

        $this->limit += $this->perPage;
    }

    protected function resolveCategoryFromPath(string $path): Category
    {
        $slugs = array_values(array_filter(explode('/', $path), fn (string $slug) => $slug !== ''));

        $parentId = Category::defaultParentKey();
        $category = null;

        foreach ($slugs as $slug) {
            $category = Category::query()
                ->where('parent_id', $parentId)
                ->where('slug', $slug)
                ->firstOrFail();

            $parentId = $category->getKey();
        }

        return $category;
    }

    public function categoryPlural(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'категория';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'категории';
        }

        return 'категорий';
    }

    public function productPlural(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'товар';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'товара';
        }

        return 'товаров';
    }

    public function render()
    {
        if (! $this->category) {
            $subcategories = Category::query()
                ->where('parent_id', Category::defaultParentKey())
                ->with(['children' => fn ($q) => $q
                    ->select(['id', 'name', 'slug', 'parent_id'])
                    ->orderBy('order')])
                ->orderBy('order')
                ->get(['id', 'name', 'slug', 'img', 'parent_id']);

            return view('pages.categories.root', [
                'category' => null,
                'subcategories' => $subcategories,
            ])->layout('layouts.catalog', [
                'title' => 'Каталог',
            ]);
        }

        if ($this->category->children()->exists()) {
            $subcategories = $this->category->children()
                ->with(['children' => fn ($q) => $q
                    ->select(['id', 'name', 'slug', 'parent_id'])
                    ->orderBy('order')])
                ->select(['id', 'name', 'slug', 'img', 'parent_id'])
                ->withCount([
                    'products as products_count' => fn ($q) => $q->where('is_active', true),
                ])
                ->orderBy('order')
                ->get();

            return view('pages.categories.branch', [
                'category' => $this->category,
                'subcategories' => $subcategories,
            ])->layout('layouts.catalog', [
                'title' => $this->category->name,
            ]);
        }

        $this->category->loadMissing('attributeDefs.unit');

        $baseQuery = Product::query()
            ->select([
                'id',
                'name',
                'slug',
                'price_amount',
                'discount_price',
                'image',
                'thumb',
                'gallery',
                'popularity',
                'sku',
            ])
            ->with([
                'attributeValues.attribute.unit',
                'attributeOptions.attribute.unit',
            ])
            ->where('is_active', true)
            ->whereHas('categories', fn ($q) => $q->whereKey($this->category->getKey()));

        if ($this->q) {
            $baseQuery->where('name', 'like', '%'.trim($this->q).'%');
        }

        $selected = $this->selected;
        $baseQuery = ProductFilterService::apply($baseQuery, $selected, $this->category);

        $baseQuery = match ($this->sort) {
            'price_asc' => $baseQuery->orderBy('price_amount')->orderBy('id'),
            'price_desc' => $baseQuery->orderByDesc('price_amount')->orderBy('id'),
            'new' => $baseQuery->orderByDesc('id'),
            default => $baseQuery->orderByDesc('popularity')->orderBy('id'),
        };

        $products = $baseQuery->limit($this->limit + 1)->get();

        if ($products->count() > $this->limit) {
            $this->hasMoreProducts = true;
            $products = $products->take($this->limit);
        } else {
            $this->hasMoreProducts = false;
        }

        return view('livewire.pages.categories.leaf', [
            'category' => $this->category,
            'products' => $products,
            'schema' => $this->filtersSchema,
        ])->layout('layouts.catalog', [
            'title' => $this->category->name,
        ]);
    }

    protected function filterDecimals(array $f): int
    {
        $meta = $f['meta'] ?? [];

        if (isset($meta['decimals']) && is_numeric($meta['decimals'])) {
            return (int) $meta['decimals'];
        }

        $step = $meta['step'] ?? null;
        if ($step === null || $step === '' || ! is_numeric($step)) {
            return 0;
        }

        $stepStr = rtrim((string) $step, '0');
        $dotPos = strpos($stepStr, '.');

        return $dotPos === false ? 0 : max(0, strlen($stepStr) - $dotPos - 1);
    }

    protected function formatNumberForChip(float $value, array $f): string
    {
        $decimals = $this->filterDecimals($f);

        return number_format($value, $decimals, ',', ' ');
    }
}
