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
        $vm = new ProductPageViewModel($product);

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

    private function normalizeHtmlForPdf(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $basePics = rtrim(public_path('pics'), '/');

        // 1) /pics/... → абсолютный файловый путь
        //    src="/pics/..."
        $html = preg_replace(
            '#(<img[^>]+src=)(["\'])/pics/([^"\']+)\2#i',
            '$1$2'.$basePics.'/$3$2',
            $html
        );

        //    src="pics/..."
        $html = preg_replace(
            '#(<img[^>]+src=)(["\'])pics/([^"\']+)\2#i',
            '$1$2'.$basePics.'/$3$2',
            $html
        );

        return $html;
    }
}
