<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\ImageDerivativesResolver;
use App\Support\ViewModels\ProductPageViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function show(Product $product): View
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'categories',
            'attributeValues.attribute',
            'attributeOptions.attribute',
        ]);

        $specs = $this->buildSpecs($product);
        $viewModel = app(ProductPageViewModel::class, ['product' => $product]);

        return view('pages.product.show', [
            'product' => $product,
            'meta' => $this->buildMeta($product, $viewModel),
            'gallery' => $this->buildGallery($product),
            'summary' => $this->buildSummary($product),
            'tabs' => $this->buildTabs($product, $specs),
            'features' => $this->buildFeatures($product),
            'seo' => [
                'url' => $viewModel->canonicalUrl(),
                'description' => $viewModel->metaDescription(),
                'image' => $viewModel->ogImage() ?: $this->primaryImageUrl($product),
                'type' => 'product',
                'schemas' => [$viewModel->seoSchema()],
            ],
        ]);
    }

    private function buildMeta(Product $product, ProductPageViewModel $viewModel): array
    {
        return [
            'page_title' => $viewModel->metaTitle(),
            'heading' => $product->name,
            'description' => $viewModel->metaDescription(),
        ];
    }

    private function buildGallery(Product $product): array
    {
        $resolver = app(ImageDerivativesResolver::class);

        $sources = collect([$product->image, $product->thumb])
            ->merge($this->normalizeGallery($product->gallery))
            ->map(fn ($value) => is_string($value) ? trim($value) : null)
            ->filter()
            ->unique()
            ->values();

        $items = $sources
            ->map(fn (string $src): array => array_merge([
                'src' => $src,
                'url' => $this->resolveImageUrl($src),
                'alt' => $product->name,
            ], $this->resolveImageMeta($src, $resolver)))
            ->values();

        $main = $items->first() ?: [
            'src' => null,
            'url' => null,
            'alt' => $product->name,
        ];

        return [
            'main' => $main,
            'items' => $items->all(),
        ];
    }

    private function buildSummary(Product $product): array
    {
        $basePrice = (int) $product->price_int;
        $finalPrice = (int) $product->price_final;
        $discountPrice = $product->discount;

        $details = collect([
            ['label' => 'Наличие', 'value' => $product->in_stock ? 'В наличии' : 'Нет в наличии'],
            // ['label' => 'Артикул', 'value' => $product->sku],
            ['label' => 'Бренд', 'value' => $product->brand],
            ['label' => 'Производитель', 'value' => $product->country],
            ['label' => 'Гарантия', 'value' => $product->warranty_display],
        ])
            ->filter(fn (array $item) => filled($item['value']))
            ->values()
            ->all();

        return [
            'price' => [
                'base' => $basePrice,
                'final' => $finalPrice,
                'discount' => $discountPrice,
                'has_discount' => (bool) $product->has_discount,
                'discount_percent' => $product->discount_percent,
            ],
            'details' => $details,
            'promo_info' => $product->promo_info,
        ];
    }

    /**
     * @param  array<int, array{name: string, value: string, source: string|null}>  $specs
     * @return array<int, array{key: string, title: string, type: string, html?: string, specs?: array<int, array{name: string, value: string, source: string|null}>}>
     */
    private function buildTabs(Product $product, array $specs): array
    {
        $tabs = [];

        if ($specs !== []) {
            $tabs[] = [
                'key' => 'specs',
                'title' => 'Характеристики',
                'type' => 'specs',
                'specs' => $specs,
            ];
        }

        $contentTabs = [
            [
                'key' => 'description',
                'title' => 'Описание',
                'html' => $product->description,
            ],
            [
                'key' => 'instructions',
                'title' => 'Инструкции',
                'html' => $this->resolveInstructionsContent($product),
            ],
            [
                'key' => 'video',
                'title' => 'Видео',
                'html' => $product->video,
            ],
        ];

        foreach ($contentTabs as $tab) {
            if ($this->hasRichContent($tab['html'] ?? null)) {
                $tabs[] = [
                    'key' => $tab['key'],
                    'title' => $tab['title'],
                    'type' => 'rich_content',
                    'html' => (string) $tab['html'],
                ];
            }
        }

        return $tabs;
    }

    private function resolveInstructionsContent(Product $product): ?string
    {
        $instructions = $product->instructions;

        if ($this->hasRichContent($instructions)) {
            return $instructions;
        }

        if ($this->hasRichContent($product->extra_description)) {
            return $product->extra_description;
        }

        return $instructions;
    }

    private function buildFeatures(Product $product): array
    {
        $displayCategory = $product->categories->firstWhere('pivot.is_primary', true);

        $attributes = $product->attributeValues
            ->pluck('attribute')
            ->merge($product->attributeOptions->pluck('attribute'))
            ->filter()
            ->unique(fn ($attribute): int => (int) $attribute->getKey())
            ->values();

        return $attributes
            ->map(function ($attribute) use ($product, $displayCategory): array {
                return [
                    'name' => $attribute->name ?? 'Атрибут',
                    'value' => $product->attrLabel($attribute, ' / ', $displayCategory) ?? '—',
                ];
            })
            ->filter(fn (array $item) => filled($item['value']))
            ->values()
            ->all();
    }

    private function primaryImageUrl(Product $product): ?string
    {
        foreach ([$product->image, $product->thumb] as $source) {
            if (! is_string($source) || trim($source) === '') {
                continue;
            }

            return $this->resolveImageUrl($source);
        }

        $gallery = $this->normalizeGallery($product->gallery);
        $firstImage = collect($gallery)
            ->map(fn (mixed $value): ?string => is_string($value) ? trim($value) : null)
            ->filter()
            ->first();

        if (! is_string($firstImage) || $firstImage === '') {
            return null;
        }

        return $this->resolveImageUrl($firstImage);
    }

    /**
     * @return array<int, array{name: string, value: string, source: string|null}>
     */
    private function buildSpecs(Product $product): array
    {
        $rawSpecs = $product->specs;

        if (is_string($rawSpecs)) {
            $decoded = json_decode($rawSpecs, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $rawSpecs = $decoded;
            }
        }

        if (! is_array($rawSpecs)) {
            return [];
        }

        return collect($rawSpecs)
            ->map(function (mixed $row, mixed $key): ?array {
                if (is_array($row)) {
                    return $this->normalizeSpecRow(
                        $row['name'] ?? $key,
                        $row['value'] ?? null,
                        $row['source'] ?? null,
                    );
                }

                return $this->normalizeSpecRow($key, $row);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{name: string, value: string, source: string|null}|null
     */
    private function normalizeSpecRow(mixed $nameRaw, mixed $valueRaw, mixed $sourceRaw = null): ?array
    {
        $name = $this->normalizeSpecString($nameRaw);
        $value = $this->normalizeSpecValue($valueRaw);

        if ($name === null || $value === null) {
            return null;
        }

        return [
            'name' => $name,
            'value' => $value,
            'source' => $this->normalizeSpecString($sourceRaw),
        ];
    }

    private function normalizeSpecString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function normalizeSpecValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        return $this->normalizeSpecString($value);
    }

    private function normalizeGallery(mixed $gallery): array
    {
        if (is_array($gallery)) {
            return collect($gallery)
                ->map(function ($item) {
                    if (is_array($item)) {
                        return $item['file'] ?? $item['path'] ?? $item['src'] ?? null;
                    }

                    return $item;
                })
                ->all();
        }

        if (is_string($gallery)) {
            $decoded = json_decode($gallery, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeGallery($decoded);
            }

            return preg_split('/[|,\s]+/', $gallery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return [];
    }

    private function hasRichContent(?string $html): bool
    {
        if (! is_string($html)) {
            return false;
        }

        $trimmed = trim($html);
        if ($trimmed === '') {
            return false;
        }

        $compact = Str::lower(preg_replace('/\s+/', '', $trimmed) ?? '');

        return ! in_array($compact, ['<p></p>', '<p><br></p>', '<p><br/></p>'], true);
    }

    private function resolveImageUrl(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (Str::startsWith($value, ['http://', 'https://', '/'])) {
            return $value;
        }

        if (Str::startsWith($value, 'storage/')) {
            return '/'.$value;
        }

        return Storage::disk('public')->url($value);
    }

    private function resolveImageMeta(string $value, ImageDerivativesResolver $resolver): array
    {
        $storagePath = null;
        $width = null;
        $height = null;
        $webpSrcset = null;

        if (Str::startsWith($value, 'storage/')) {
            $storagePath = Str::after($value, 'storage/');
        } elseif (Str::startsWith($value, '/storage/')) {
            $storagePath = Str::after($value, '/storage/');
        } elseif (! Str::startsWith($value, ['http://', 'https://', '/'])) {
            $storagePath = $value;
        }

        if (is_string($storagePath) && $storagePath !== '') {
            $disk = Storage::disk('public');

            if ($disk->exists($storagePath)) {
                $absolutePath = $disk->path($storagePath);

                if (is_file($absolutePath)) {
                    $size = getimagesize($absolutePath);

                    if (is_array($size)) {
                        [$width, $height] = $size;
                    }
                }
            }

            $webpSrcset = $resolver->buildWebpSrcset($storagePath);
        }

        return [
            'width' => $width,
            'height' => $height,
            'webp_srcset' => $webpSrcset,
        ];
    }
}
