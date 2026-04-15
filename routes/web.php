<?php

use App\Http\Controllers\MailPreviewController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPrintController;
use App\Http\Middleware\CartNotEmpty;
use App\Livewire\Checkout\Wizard as CheckoutWizard;
use App\Livewire\Pages\Cart\Index as CartIndex;
use App\Livewire\Pages\Categories\LeafCategoryPage;
use App\Livewire\Pages\Compare\Page as ComparePage;
use App\Livewire\Pages\Favorites\Index as FavoritesIndex;
use App\Livewire\Pages\Orders\Index as OrdersIndex;
use App\Livewire\Pages\Orders\Show as OrderShow;
use App\Models\ImportRun;
use App\Models\Page;
use App\Support\Products\ProductSearchService;
use App\Support\Seo\SiteSeoDataBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function (SiteSeoDataBuilder $seoBuilder) {
    $homePage = Page::query()->where('slug', 'home')->first();

    return view('pages.home', [
        'homePage' => $homePage,
        'seo' => [
            'description' => $homePage?->meta_description ?: $seoBuilder->descriptionFromHtml($homePage?->content),
        ],
    ]);
})->name('home');

Route::get('/page/{page:slug}', PageController::class)
    ->name('page.show');

Route::livewire('/catalog/{path?}', LeafCategoryPage::class)
    ->where('path', '.*')
    ->name('catalog.leaf');

Route::get('/product/{product:slug}', [ProductController::class, 'show'])
    ->name('product.show');

Route::get('/search', function (Request $request, ProductSearchService $search) {
    $qOriginal = (string) $request->query('q', '');
    $q = $search->normalizeQuery($qOriginal);

    if (mb_strlen($q) < 2) {
        return view('pages.search', [
            'q' => $qOriginal,
            'items' => collect(),
            'seo' => [
                'description' => $qOriginal !== ''
                    ? 'Результаты поиска по запросу «'.$qOriginal.'» на сайте '.config('app.name').'.'
                    : null,
                'type' => 'website',
            ],
        ]);
    }

    $items = $search->searchPage($qOriginal, 24)
        ->withQueryString();

    return view('pages.search', [
        'q' => $qOriginal,
        'items' => $items,
        'seo' => [
            'description' => $qOriginal !== ''
                ? 'Результаты поиска по запросу «'.$qOriginal.'» на сайте '.config('app.name').'.'
                : null,
            'type' => 'website',
        ],
    ]);
})->name('search');

Route::get('/product/{product:slug}/print', ProductPrintController::class)
    ->name('product.print');

Route::get('/compare', ComparePage::class)
    ->name('compare.index');

Route::get('/cart', CartIndex::class)
    ->name('cart.index');

Route::get('/checkout', CheckoutWizard::class)
    ->name('checkout.index')
    ->middleware(CartNotEmpty::class);

Route::get('/checkout/success/{date}/{seq}', function (string $date, string $seq) {
    $orderNumber = "{$date}/{$seq}";

    return view('pages.checkout.success', compact('orderNumber'));
})->where([
    'date' => '\d{2}-\d{2}-\d{2}',
    'seq' => '\d+',
])->name('checkout.success');

Route::get('/favorites', FavoritesIndex::class)
    ->name('favorites.index');

Route::prefix('user')->middleware(['auth'])->group(function (): void {
    Route::livewire('/orders', OrdersIndex::class)
        ->name('user.orders.index');

    Route::livewire('/orders/{date}/{seq}', OrderShow::class)
        ->where([
            'date' => '\d{2}-\d{2}-\d{2}',
            'seq' => '\d+',
        ])
        ->name('user.orders.show');
});

Route::get('/market.xml', function () {
    $disk = Storage::disk('public');
    $relativePath = 'feeds/yandex-market.xml';

    abort_unless($disk->exists($relativePath), 404);

    return response()->file($disk->path($relativePath), [
        'Content-Type' => 'text/xml; charset=utf-8',
    ]);
})->name('feeds.yandex-market');

Route::middleware(['web', 'auth'])
    ->get('/admin/tools/download-export/{token}/{name}', function (string $token, string $name) {
        abort_unless(preg_match('/^[a-f0-9]{16}$/i', $token) === 1, 404);

        $key = "exports/tmp/{$token}.path";
        abort_unless(Storage::disk('local')->exists($key), 404);

        $absPath = trim((string) Storage::disk('local')->get($key));
        abort_unless(is_file($absPath), 404);

        $downloadName = basename($name);
        Storage::disk('local')->delete($key);

        return response()->download($absPath, $downloadName);
    })
    ->name('admin.tools.download-export');

Route::middleware(['web', 'auth'])
    ->get('/admin/tools/download-import/{run}', function (ImportRun $run) {
        $stored = $run->stored_path;

        if (! $stored) {
            abort(404);
        }

        if (! str_starts_with($stored, DIRECTORY_SEPARATOR)) {
            $absPath = Storage::disk('local')->path($stored);
        } else {
            $absPath = $stored;
        }

        abort_unless(is_file($absPath), 404);

        $downloadName = $run->source_filename ?: basename($absPath);

        return response()->download($absPath, $downloadName);
    })
    ->name('admin.tools.download-import');

if (app()->environment(['local', 'testing'])) {
    Route::prefix('_preview/mail')
        ->name('mail.preview.')
        ->group(function (): void {
            Route::get('/', [MailPreviewController::class, 'index'])->name('index');
            Route::get('/{preview}', [MailPreviewController::class, 'show'])->name('show');
        });
}

require __DIR__.'/settings.php';
