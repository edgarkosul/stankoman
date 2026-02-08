<?php

namespace App\Support\ViewModels;


use App\Models\Product;
use App\Models\ProductTab;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Collection;
use App\Support\Seo\SeoTextExtractor;
use App\Support\ImageDerivativesResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ProductPageViewModel
{
    public function __construct(public Product $product) {}

    public function metaTitle(): string
    {
        // Если в товаре явно задан META Title — используем его
        if (filled($this->product->title)) {
            return $this->product->title;
        }

        // Иначе — динамический fallback
        $price = number_format($this->product->price_final, 0, ' ', ' ');

        return "{$this->product->name} купить по цене {$price} ₽ в KratonShop";
    }

    public function isMobile()
    {
        $agent = new Agent();
        return $agent->isMobile();
    }

    public function metaDescription(): ?string
    {
        if (filled($this->product->meta_description)) {
            return $this->product->meta_description;
        }
        $desk = app(SeoTextExtractor::class)->extractDescriptionFromHtml($this->product->description);
        return $desk;
    }

    public function images(): array
    {
        // источники в порядке приоритета
        $srcs = collect([
            $this->product->image,   // основная
            $this->product->thumb,   // миниатюра как запасной вариант
        ])
            ->merge($this->normalizeGallery($this->product->gallery ?? null))
            ->map(fn($v) => is_string($v) ? trim($v) : null)
            ->filter()   // убираем null/пустые строки
            ->unique()
            ->values();

        return $srcs->all();
    }

    public function ogImage(): ?string
    {
        $images = $this->schemaImages();

        return $images[0] ?? null;
    }

    public function canonicalUrl(): string
    {
        $request = request();
        $queryKeys = array_keys($request->query());

        if ($queryKeys === []) {
            return $request->url();
        }

        $trackingKeys = [];
        foreach ($queryKeys as $key) {
            $normalized = strtolower((string) $key);
            if (str_starts_with($normalized, 'utm_')) {
                $trackingKeys[] = $key;
                continue;
            }
            if (in_array($normalized, [
                'gclid',
                'fbclid',
                'yclid',
                'ymclid',
                'msclkid',
                'ttclid',
                'igshid',
                '_openstat',
                'openstat',
            ], true)) {
                $trackingKeys[] = $key;
            }
        }

        if ($trackingKeys === []) {
            return $request->fullUrl();
        }

        return $request->fullUrlWithoutQuery($trackingKeys);
    }

    private function normalizeGallery($gallery): array
    {
        if (is_array($gallery)) {
            return $gallery;
        }

        if (is_string($gallery)) {
            // пробуем как JSON
            $decoded = json_decode($gallery, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // иначе — делим по | , или пробелам
            return preg_split('/[|,\s]+/', $gallery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return [];
    }

    public function priceBlock(): array
    {
        $stavka_nds = config('settings.product.stavka_nds', 0);
        $dns = $this->product->with_dns ? "(НДС $stavka_nds% в том числе)" : "(+ НДС $stavka_nds%)";
        return [
            'price'      => $this->product->price,
            'discount_price'  => $this->product->discount_price ?? null,
            'in_stock'   => $this->product->in_stock ?? true,
            'sku'        => $this->product->sku ?? null,
            'with_dns'  => $dns,
        ];
    }

    public function short(): ?string
    {
        return $this->product->short;
    }

    public function specs(): ?string
    {
        return $this->product->specs; // пока как есть (HTML/text)
    }

    public function related(): array
    {
        // Заглушка: возьми 8 товаров из той же категории
        $cat = $this->primaryCategory();
        if (!$cat) return [];
        return $this->product->whereHas('categories', fn($q) => $q->whereKey($cat->getKey()))
            ->where('id', '!=', $this->product->id)
            ->latest('id')
            ->limit(8)
            ->get(['id', 'name', 'slug', 'price', 'thumb'])
            ->all();
    }

    public function promoInfo()
    {
        return $this->product->promo_info;
    }

    public function modalProducts()
    {
        $resolver = app(ImageDerivativesResolver::class);
        $toUrl = function (?string $value): string {
            $value = (string) $value;
            if ($value === '') {
                return '';
            }

            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
                return $value;
            }

            if (str_starts_with($value, 'storage/')) {
                return '/' . $value;
            }

            return Storage::disk('public')->url($value);
        };

        $imagePath = is_string($this->product->image) ? trim($this->product->image) : '';
        $imageUrl = $toUrl($imagePath);
        $webpSrcset = $imagePath !== '' ? $resolver->buildWebpSrcset($imagePath) : null;

        return [
            $this->product->id => [
                "id" => $this->product->id,
                "name" => $this->product->name,
                "price" => $this->product->price_int,
                "price_final" => $this->product->price_final,
                "has_discount" => $this->product->has_discount,
                "image" => $imageUrl,
                "webpSrcset" => $webpSrcset,
                "slug" => $this->product->slug,
                "url" => route('product.show', $this->product->slug),
            ]
        ];
    }

    public function seoSchema(): array
    {
        $priceBlock = $this->priceBlock();

        // 1) Цена: сначала скидочная, если есть, иначе обычная
        $rawPrice = $priceBlock['discount_price'] && $priceBlock['discount_price'] > 0
            ? $priceBlock['discount_price']
            : $priceBlock['price'];

        $price = $rawPrice !== null ? (string) $rawPrice : '0';

        // 2) Наличие
        $inStock = $priceBlock['in_stock'] ?? true;

        $availability = $inStock
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';

        // 3) Картинки (абсолютные URL, WebP-derivatives где возможно)
        $images = $this->schemaImages();

        // 4) Описание для Schema.org
        $description = $this->schemaDescription();

        // 5) Brand (если есть)
        $brand = null;
        if (!empty($this->product->brand)) {
            $brand = [
                '@type' => 'Brand',
                'name'  => $this->product->brand,
            ];
        }

        // 6) Category (по основной категории)
        $primaryCategory = $this->primaryCategory();
        $categoryName    = $primaryCategory?->name;

        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => $this->product->name,
            'description' => $description ?: null,
            'image'       => $images,
            'sku'         => $this->product->sku ?? (string) $this->product->id,
            'brand'       => $brand,
            'category'    => $categoryName ?: null,
            'offers'      => [
                '@type'         => 'Offer',
                'priceCurrency' => 'RUB', // при желании можно взять из поля product->currency
                'price'         => $price,
                'availability'  => $availability,
                'url'           => route('product.show', $this->product),
            ],
        ];

        // Уберём null-ы на верхнем уровне (brand / category / description, если их нет)
        return array_filter($data, fn($v) => $v !== null);
    }

    private function schemaImages(): array
    {
        $images = array_values(array_filter(
            $this->images(),
            fn($value) => is_string($value) && trim($value) !== ''
        ));

        if ($images === []) {
            return [];
        }

        $resolver = app(ImageDerivativesResolver::class);
        $mainSrcsetMap = $this->parseWebpSrcset(
            $resolver->buildWebpSrcset($images[0])
        );
        $targetWidth = $this->selectSchemaImageWidth($mainSrcsetMap);

        $resolved = [];
        foreach ($images as $index => $path) {
            $webpUrl = null;

            if ($targetWidth !== null) {
                $srcsetMap = $index === 0
                    ? $mainSrcsetMap
                    : $this->parseWebpSrcset($resolver->buildWebpSrcset($path));

                $webpUrl = $srcsetMap[$targetWidth] ?? null;
            }

            $finalUrl = $webpUrl ?: $this->absoluteImageUrl($path);
            if ($finalUrl !== null && $finalUrl !== '') {
                $resolved[] = $finalUrl;
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, string>
     */
    private function parseWebpSrcset(?string $srcset): array
    {
        if (! is_string($srcset) || trim($srcset) === '') {
            return [];
        }

        $map = [];
        foreach (array_map('trim', explode(',', $srcset)) as $item) {
            if ($item === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $item);
            if (! is_array($parts) || count($parts) < 2) {
                continue;
            }

            $url = $parts[0] ?? '';
            $descriptor = $parts[1] ?? '';
            if ($url === '' || ! str_ends_with($descriptor, 'w')) {
                continue;
            }

            $width = (int) rtrim($descriptor, 'w');
            if ($width <= 0) {
                continue;
            }

            $map[$width] = $url;
        }

        return $map;
    }

    private function selectSchemaImageWidth(array $srcsetMap): ?int
    {
        if ($srcsetMap === []) {
            return null;
        }

        if (isset($srcsetMap[1600])) {
            return 1600;
        }

        $widths = array_keys($srcsetMap);
        rsort($widths, SORT_NUMERIC);

        return $widths[0] ?? null;
    }

    private function absoluteImageUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        if (Str::startsWith($value, '//')) {
            $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $value;
        }

        if (Str::startsWith($value, 'storage/')) {
            return url('/' . $value);
        }

        if (Str::startsWith($value, '/')) {
            return url($value);
        }

        return Storage::disk('public')->url($value);
    }


    private function primaryCategory()
    {
        // Если в pivot будет is_primary — используй его.
        return $this->product->primaryCategory();
    }

    /**
     * Содержимое вкладок продуктов.
     * Кешируется, т.к. одно и то же для всех товаров.
     *
     * @return \Illuminate\Support\Collection<string,string>
     */
    public function tabContents(): Collection
    {
        return Cache::rememberForever('product_tabs.contents', function () {
            return ProductTab::query()
                ->where('is_active', true)
                ->orderBy('position')
                ->get(['key', 'content'])
                ->pluck('content', 'key');
        });
    }

    /**
     * Удобный хелпер: получить одну вкладку по ключу.
     */
    public function tabContent(string $key, string $default = ''): string
    {
        return $this->tabContents()->get($key, $default);
    }

    // ...

    public function schemaDescription(int $maxLength = 400): ?string
    {
        $source = $this->product->meta_description ?: $this->metaDescription();

        if (!$source) {
            return null;
        }

        $text = strip_tags($source);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // 3) Ограничим длину, чтобы description был компактным и читабельным
        return Str::limit($text, $maxLength, '…');
    }
}
