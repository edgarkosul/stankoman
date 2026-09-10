<?php

namespace App\Http\Controllers;

use App\Models\Attribute as AttributeDef;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Products\ProductSpecs;
use App\Support\ViewModels\ProductPageViewModel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class ProductPrintController extends Controller
{
    private const VIEW = 'pages.product.pdf.offer';

    /** Папка кэша на диске local, то есть storage/app/private — наружу не отдаётся. */
    private const CACHE_DIR = 'pdf-offers';

    /**
     * Ручной рубильник: поднять число, если оферту сменили не в шаблоне,
     * а здесь, в сборке данных, и прежние файлы надо обесценить.
     */
    private const CACHE_VERSION = 1;

    public function __invoke(Request $request, Product $product): Response
    {
        $filename = 'InterTooler_'.preg_replace('/[^\p{L}\p{N}\-_]+/u', '_', $product->name).'.pdf';

        return response($this->pdfBytes($product), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $request->boolean('dl')
                    ? HeaderUtils::DISPOSITION_ATTACHMENT
                    : HeaderUtils::DISPOSITION_INLINE,
                $filename,
                $this->asciiFilename($filename),
            ),
            // PDF нельзя разметить <meta name="robots">, поэтому закрываем заголовком:
            // иначе оферта конкурирует в выдаче с карточкой товара, а ?dl=1 плодит дубли.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Сборка оферты занимает 16-18 секунд и всё это время держит php-fpm
     * воркер, а краулеры ходят по одним и тем же карточкам сотнями в сутки —
     * поэтому готовый файл кладём на диск и дальше отдаём его.
     */
    private function pdfBytes(Product $product): string
    {
        $path = $this->cachePath($product);

        if (is_file($path)) {
            $cached = @file_get_contents($path);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $bytes = $this->renderPdf($product);

        $this->cache($product, $path, $bytes);

        return $bytes;
    }

    private function renderPdf(Product $product): string
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
            'specs' => app(ProductSpecs::class)->rows($product),
            'descriptionHtml' => $descriptionHtml,
        ];

        return Pdf::loadView(self::VIEW, $data)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'chroot' => [
                    base_path(),
                    storage_path(),
                ],
                'isRemoteEnabled' => true,
                'defaultFont' => 'RobotoCondensed',
            ])
            ->output();
    }

    /**
     * Ключ считаем от всего, что попадает в оферту: сам товар, его значения
     * и опции атрибутов (правки в них не трогают products.updated_at),
     * реквизиты из настроек и содержимое самого шаблона.
     */
    private function cachePath(Product $product): string
    {
        $signature = [
            self::CACHE_VERSION,
            $product->updated_at?->getTimestamp(),
            ProductAttributeValue::query()
                ->where('product_id', $product->id)
                ->max('updated_at'),
            DB::table('product_attribute_option')
                ->where('product_id', $product->id)
                ->max('updated_at'),
            md5(serialize(config('company'))),
            $this->viewFingerprint(),
        ];

        $hash = substr(hash('sha256', implode('|', array_map(strval(...), $signature))), 0, 16);

        // Точка после id обязательна: по ней же чистим прежние версии товара,
        // и без неё маска product-1.* цепляла бы product-10.
        return Storage::disk('local')->path(self::CACHE_DIR."/product-{$product->id}.{$hash}.pdf");
    }

    private function viewFingerprint(): string
    {
        try {
            $path = View::getFinder()->find(self::VIEW);
        } catch (\InvalidArgumentException) {
            return 'no-view';
        }

        return (string) @md5_file($path);
    }

    private function cache(Product $product, string $path, string $bytes): void
    {
        $directory = dirname($path);

        File::ensureDirectoryExists($directory);

        // Пишем во временный файл и переименовываем: rename на месте атомарен,
        // поэтому соседний запрос не прочитает наполовину дописанную оферту.
        $temporary = $path.'.'.Str::random(8).'.tmp';

        if (File::put($temporary, $bytes) === false) {
            return;
        }

        File::move($temporary, $path);

        // Прежние версии этого товара больше не нужны — держим по файлу на товар.
        foreach (File::glob($directory."/product-{$product->id}.*.pdf") as $stale) {
            if ($stale !== $path) {
                File::delete($stale);
            }
        }
    }

    /**
     * Запасное имя для Content-Disposition: в основном стоит кириллица,
     * а заголовку нужен ASCII-вариант на случай старого клиента.
     */
    private function asciiFilename(string $filename): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9\-_.]+/', '_', Str::ascii($filename)) ?? '';
        $ascii = trim($ascii, '_');

        return $ascii === '' || $ascii === '.pdf' ? 'offer.pdf' : $ascii;
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
