<?php

namespace App\Http\Controllers;

use App\Models\Attribute as AttributeDef;
use App\Models\Product;
use App\Support\ViewModels\ProductPageViewModel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ProductPrintController extends Controller
{
    public function __invoke(Request $request, Product $product): Response
    {
        $vm = app(ProductPageViewModel::class, ['product' => $product]);

        $images = method_exists($vm, 'images') ? $vm->images() : [];
        $cover = $images[0] ?? null;

        if (is_string($cover) && $cover !== '') {
            $coverPath = parse_url($cover, PHP_URL_PATH) ?: $cover;

            if (Str::startsWith($coverPath, '/storage/')) {
                $cover = public_path(ltrim($coverPath, '/'));
            } elseif (Str::startsWith($coverPath, 'storage/')) {
                $cover = public_path($coverPath);
            } elseif (! Str::startsWith($coverPath, ['http://', 'https://', '/'])) {
                $cover = public_path('storage/'.ltrim($coverPath, '/'));
            }
        }

        $descriptionHtml = $this->normalizeHtmlForPdf($product->description ?? '');

        $data = [
            'product' => $product,
            'cover' => $cover,
            'sku' => $product->sku ?? $product->id,
            'price' => number_format((float) ($product->price_amount ?? 0), 0, ',', ' ').' ₽',
            'attributes' => $this->attributesForPdf($product),
            'specs' => $this->specsForPdf($product),
            'descriptionHtml' => $descriptionHtml,
        ];

        $pdf = Pdf::loadView('pages.product.pdf.offer', $data)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'chroot' => [
                    base_path(),
                    storage_path(),
                ],
                'isRemoteEnabled' => true,
                'defaultFont' => 'RobotoCondensed',
            ]);

        $filename = 'InterTooler_'.preg_replace('/[^\p{L}\p{N}\-_]+/u', '_', $product->name).'.pdf';

        return $request->boolean('dl') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    private function attributesForPdf(Product $product): array
    {
        $product->loadMissing([
            'attributeValues.attribute.unit',
            'attributeOptions.attribute.unit',
            'categories',
        ]);

        $rows = [];

        if ($category = $product->primaryCategory()) {
            $attrs = $category->attributeDefs()
                ->with('unit')
                ->wherePivot('visible_in_specs', true)
                ->orderByPivot('filter_order')
                ->get();
        } else {
            $filledIds = $product->filledAttributeIds();
            $attrs = AttributeDef::with('unit')
                ->whereIn('id', $filledIds)
                ->orderBy('name')
                ->get();
        }

        foreach ($attrs as $attribute) {
            $label = $product->attrLabel($attribute, ' / ');
            if ($label !== null && $label !== '') {
                $rows[] = [$attribute->name, $label];
            }
        }

        return $rows;
    }

    /**
     * Берём те же данные, что и вкладка specs на витрине.
     *
     * @return array<int, array{name: string, value: string, source: string|null}>
     */
    private function specsForPdf(Product $product): array
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
                    return $this->normalizeSpecRowForPdf(
                        $row['name'] ?? $key,
                        $row['value'] ?? null,
                        $row['source'] ?? null,
                    );
                }

                return $this->normalizeSpecRowForPdf($key, $row);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{name: string, value: string, source: string|null}|null
     */
    private function normalizeSpecRowForPdf(mixed $nameRaw, mixed $valueRaw, mixed $sourceRaw = null): ?array
    {
        $name = $this->normalizeSpecStringForPdf($nameRaw);
        $value = $this->normalizeSpecValueForPdf($valueRaw);

        if ($name === null || $value === null) {
            return null;
        }

        return [
            'name' => $name,
            'value' => $value,
            'source' => $this->normalizeSpecStringForPdf($sourceRaw),
        ];
    }

    private function normalizeSpecStringForPdf(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function normalizeSpecValueForPdf(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        return $this->normalizeSpecStringForPdf($value);
    }

    private function normalizeHtmlForPdf(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $basePics = rtrim(public_path('pics'), '/');

        $html = preg_replace(
            '#(<img[^>]+src=)(["\'])/pics/([^"\']+)\2#i',
            '$1$2'.$basePics.'/$3$2',
            $html
        );

        $html = preg_replace(
            '#(<img[^>]+src=)(["\'])pics/([^"\']+)\2#i',
            '$1$2'.$basePics.'/$3$2',
            $html
        );

        return $html;
    }
}
