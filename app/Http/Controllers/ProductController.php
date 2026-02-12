<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $product->load([
            'categories',
            'attributeValues.attribute',
            'attributeOptions.attribute',
        ]);

        return view('pages.product.show', [
            'product' => $product,
            'meta' => $this->buildMeta($product),
            'gallery' => $this->buildGallery($product),
            'summary' => $this->buildSummary($product),
            'contentSections' => $this->buildContentSections($product),
            'features' => $this->buildFeatures($product),
        ]);
    }

    private function buildMeta(Product $product): array
    {
        return [
            'page_title' => $product->meta_title ?: $product->name,
            'heading' => $product->name,
            'description' => $product->meta_description,
        ];
    }

    private function buildGallery(Product $product): array
    {
        $sources = collect([$product->image, $product->thumb])
            ->merge($this->normalizeGallery($product->gallery))
            ->map(fn ($value) => is_string($value) ? trim($value) : null)
            ->filter()
            ->unique()
            ->values();

        $items = $sources
            ->map(fn (string $src): array => [
                'src' => $src,
                'url' => $this->resolveImageUrl($src),
                'alt' => $product->name,
            ])
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
            ['label' => 'Артикул', 'value' => $product->sku],
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
                'base_formatted' => number_format($basePrice, 0, ' ', ' ') . ' ₽',
                'final_formatted' => number_format($finalPrice, 0, ' ', ' ') . ' ₽',
                'discount_formatted' => $discountPrice !== null
                    ? number_format((int) $discountPrice, 0, ' ', ' ') . ' ₽'
                    : null,
            ],
            'details' => $details,
            'promo_info' => $product->promo_info,
        ];
    }

    private function buildContentSections(Product $product): array
    {
        $sections = [];

        $sections[] = [
            'key' => 'description',
            'title' => 'Описание',
            'html' => $product->description,
            'has_content' => $this->hasRichContent($product->description),
            'empty_text' => 'Описание пока не заполнено.',
        ];

        $extraDescription = $product->extra_description;
        if ($this->hasRichContent($extraDescription)) {
            $sections[] = [
                'key' => 'extra_description',
                'title' => 'Дополнительное описание',
                'html' => $extraDescription,
                'has_content' => true,
                'empty_text' => null,
            ];
        }

        return $sections;
    }

    private function buildFeatures(Product $product): array
    {
        $fromValues = $product->attributeValues
            ->map(fn ($value): array => [
                'name' => $value->attribute?->name ?? 'Атрибут',
                'value' => $value->display_value ?? '—',
            ]);

        $fromOptions = $product->attributeOptions
            ->groupBy(fn ($option) => (string) ($option->pivot?->attribute_id ?? ''))
            ->map(fn (Collection $options): array => [
                'name' => $options->first()?->attribute?->name ?? 'Опции',
                'value' => $options->pluck('value')->filter()->join(', '),
            ])
            ->values();

        return $fromValues
            ->concat($fromOptions)
            ->filter(fn (array $item) => filled($item['value']))
            ->values()
            ->all();
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
            return '/' . $value;
        }

        return Storage::disk('public')->url($value);
    }
}
