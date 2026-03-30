<?php

namespace App\Support\Feeds;

use App\Models\Category;
use App\Models\Product;
use App\Support\Seo\SeoTextExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class YandexMarketFeedGenerator
{
    private const TARGET_PATH = 'feeds/yandex-market.xml';

    private const PRODUCT_DB_CHUNK = 500;

    public function __construct(private SeoTextExtractor $seoTextExtractor) {}

    /**
     * @return array{path: string, categories: int, offers: int}
     */
    public function generate(): array
    {
        $disk = Storage::disk('public');
        $targetPath = $disk->path(self::TARGET_PATH);
        $tmpPath = $targetPath.'.tmp';

        File::ensureDirectoryExists(dirname($targetPath));

        $categoryMap = $this->feedCategories();
        $offerCount = 0;

        $xml = new \XMLWriter;
        $xml->openUri($tmpPath);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);

        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', now()->format('Y-m-d H:i'));

        $xml->startElement('shop');
        $xml->writeElement('name', $this->shopName());
        $xml->writeElement('company', $this->companyName());
        $xml->writeElement('url', $this->baseUrl());

        $email = trim((string) config('company.public_email', config('mail.from.address')));
        if ($email !== '') {
            $xml->writeElement('email', $email);
        }

        $xml->startElement('currencies');
        $xml->startElement('currency');
        $xml->writeAttribute('id', 'RUR');
        $xml->writeAttribute('rate', '1');
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('categories');
        foreach ($categoryMap as $category) {
            $xml->startElement('category');
            $xml->writeAttribute('id', (string) $category['id']);

            if ($category['parent_id'] !== null) {
                $xml->writeAttribute('parentId', (string) $category['parent_id']);
            }

            $xml->text($category['name']);
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('offers');

        Product::query()
            ->select([
                'id',
                'name',
                'slug',
                'brand',
                'price_amount',
                'discount_price',
                'in_stock',
                'is_active',
                'is_in_yml_feed',
                'description',
                'short',
                'meta_description',
                'image',
                'thumb',
                'gallery',
            ])
            ->where('is_active', true)
            ->where('is_in_yml_feed', true)
            ->where('price_amount', '>', 0)
            ->whereHas('categories', function ($query): void {
                $query
                    ->where('categories.is_active', true)
                    ->where('categories.slug', '!=', Category::stagingSlug());
            })
            ->with(['categories' => function ($query): void {
                $query
                    ->select(['categories.id', 'categories.name', 'categories.parent_id', 'categories.slug', 'categories.is_active'])
                    ->where('categories.is_active', true)
                    ->where('categories.slug', '!=', Category::stagingSlug());
            }])
            ->orderBy('id')
            ->chunkById(self::PRODUCT_DB_CHUNK, function ($products) use ($xml, &$offerCount, $categoryMap): void {
                foreach ($products as $product) {
                    $categoryId = $this->resolveCategoryId($product, $categoryMap);

                    if ($categoryId === null) {
                        continue;
                    }

                    $this->writeOffer($xml, $product, $categoryId);
                    $offerCount++;
                }
            });

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();

        if (! rename($tmpPath, $targetPath)) {
            throw new \RuntimeException("Unable to move [{$tmpPath}] to [{$targetPath}].");
        }

        return [
            'path' => $targetPath,
            'categories' => count($categoryMap),
            'offers' => $offerCount,
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, parent_id: ?int}>
     */
    private function feedCategories(): array
    {
        $feedCategoryIds = DB::table('product_categories')
            ->join('products', 'products.id', '=', 'product_categories.product_id')
            ->join('categories', 'categories.id', '=', 'product_categories.category_id')
            ->where('products.is_active', true)
            ->where('products.is_in_yml_feed', true)
            ->where('products.price_amount', '>', 0)
            ->where('categories.is_active', true)
            ->where('categories.slug', '!=', Category::stagingSlug())
            ->distinct()
            ->pluck('product_categories.category_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($feedCategoryIds === []) {
            return [];
        }

        $allCategories = Category::query()
            ->active()
            ->withoutStaging()
            ->select(['id', 'name', 'parent_id'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $included = [];

        foreach ($feedCategoryIds as $categoryId) {
            $node = $allCategories->get($categoryId);

            while ($node) {
                $included[$node->id] = [
                    'id' => (int) $node->id,
                    'name' => trim((string) $node->name),
                    'parent_id' => $node->parent_id !== Category::defaultParentKey() && $allCategories->has($node->parent_id)
                        ? (int) $node->parent_id
                        : null,
                ];

                if ($node->parent_id === Category::defaultParentKey()) {
                    break;
                }

                $node = $allCategories->get($node->parent_id);
            }
        }

        uasort($included, static function (array $left, array $right): int {
            return [$left['parent_id'] ?? 0, $left['name'], $left['id']] <=> [$right['parent_id'] ?? 0, $right['name'], $right['id']];
        });

        return $included;
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_id: ?int}>  $categoryMap
     */
    private function resolveCategoryId(Product $product, array $categoryMap): ?int
    {
        $primaryCategoryId = $product->primaryCategory()?->id;

        if (is_int($primaryCategoryId) && isset($categoryMap[$primaryCategoryId])) {
            return $primaryCategoryId;
        }

        foreach ($product->categories as $category) {
            if (isset($categoryMap[$category->id])) {
                return (int) $category->id;
            }
        }

        return null;
    }

    private function writeOffer(\XMLWriter $xml, Product $product, int $categoryId): void
    {
        $xml->startElement('offer');
        $xml->writeAttribute('id', (string) $product->getKey());
        $xml->writeAttribute('available', $product->in_stock ? 'true' : 'false');

        $xml->writeElement('url', $this->productUrl($product));
        $xml->writeElement('price', (string) $product->price_final);

        if ($product->has_discount && $product->price_int > 0 && $product->price_final < $product->price_int) {
            $xml->writeElement('oldprice', (string) $product->price_int);
        }

        $xml->writeElement('currencyId', 'RUR');
        $xml->writeElement('categoryId', (string) $categoryId);

        foreach ($this->imageUrls($product) as $imageUrl) {
            $xml->writeElement('picture', $imageUrl);
        }

        $vendor = trim((string) $product->brand);
        if ($vendor !== '') {
            $xml->writeElement('vendor', $vendor);
        }

        $xml->writeElement('name', $product->name);

        $description = $this->description($product);
        if ($description !== null) {
            $xml->writeElement('description', $description);
        }

        $xml->endElement();
    }

    /**
     * @return list<string>
     */
    private function imageUrls(Product $product): array
    {
        return collect([
            $product->image,
            $product->thumb,
        ])
            ->merge($this->normalizeGallery($product->gallery))
            ->map(fn ($value): ?string => $this->normalizeImageUrl($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function normalizeGallery(mixed $gallery): array
    {
        if (is_array($gallery)) {
            return array_values(array_filter(array_map('strval', $gallery)));
        }

        if (! is_string($gallery) || trim($gallery) === '') {
            return [];
        }

        $decoded = json_decode($gallery, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }

        return preg_split('/[|,\s]+/', $gallery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function normalizeImageUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $path = trim($value);

        if (Str::startsWith($path, '//')) {
            return 'https:'.$path;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (Str::startsWith($path, '/')) {
            return rtrim($this->baseUrl(), '/').$path;
        }

        if (Str::startsWith($path, 'storage/')) {
            return rtrim($this->baseUrl(), '/').'/'.$path;
        }

        return rtrim($this->baseUrl(), '/').'/storage/'.ltrim($path, '/');
    }

    private function description(Product $product): ?string
    {
        $source = trim((string) ($product->meta_description ?? ''));

        if ($source === '') {
            $html = trim((string) ($product->description ?: $product->short ?: ''));

            if ($html !== '') {
                $source = (string) $this->seoTextExtractor->extractDescriptionFromHtml($html, 800);
            }
        }

        $source = trim(strip_tags($source));

        if ($source === '') {
            return null;
        }

        return Str::limit((string) preg_replace('/\s+/u', ' ', $source), 800, '...');
    }

    private function shopName(): string
    {
        return trim((string) config('settings.general.shop_name', config('app.name'))) ?: (string) config('app.name');
    }

    private function companyName(): string
    {
        $company = trim((string) config('company.legal_name'));

        return $company !== '' ? $company : $this->shopName();
    }

    private function baseUrl(): string
    {
        $configured = trim((string) config('company.site_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) config('app.url'), '/');
    }

    private function productUrl(Product $product): string
    {
        return $this->baseUrl().'/product/'.ltrim((string) $product->slug, '/');
    }
}
