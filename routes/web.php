<?php

use App\Http\Controllers\ChatResumeController;
use App\Http\Controllers\ChatUnreadController;
use App\Http\Controllers\LegacyKratonRedirectController;
use App\Http\Controllers\MailPreviewController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPrintController;
use App\Http\Middleware\CartNotEmpty;
use App\Http\Middleware\EnsureStorefrontCustomer;
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

Route::get('/_legacy/kraton/resolve', LegacyKratonRedirectController::class)
    ->name('legacy.kraton.resolve');

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

    $outcome = $search->searchPageOutcome($qOriginal, 24);

    return view('pages.search', [
        'q' => $qOriginal,
        'items' => $outcome->result->withQueryString(),
        // Слова, без которых пришлось искать: выдача «не совсем про то»
        // с объяснением — подсказка, без объяснения — похоже на поломку.
        'unmatched' => $outcome->relaxed ? $outcome->unmatched : [],
        'seo' => [
            'description' => $qOriginal !== ''
                ? 'Результаты поиска по запросу «'.$qOriginal.'» на сайте '.config('app.name').'.'
                : null,
            'type' => 'website',
        ],
    ]);
})->name('search');

// Каждый вызов синхронно строит PDF через Dompdf и занимает php-fpm воркер
// на секунды, поэтому маршрут ограничен по частоте: 12 карточек в минуту с IP
// человеку хватает с запасом, а краулеру не даёт выесть весь пул.
Route::get('/product/{product:slug}/print', ProductPrintController::class)
    ->middleware('throttle:12,1')
    ->name('product.print');

Route::get('/compare', ComparePage::class)
    ->name('compare.index');

Route::get('/cart', CartIndex::class)
    ->name('cart.index');

Route::get('/checkout', CheckoutWizard::class)
    ->name('checkout.index')
    ->middleware([CartNotEmpty::class, EnsureStorefrontCustomer::class]);

Route::get('/checkout/success/{date}/{seq}', function (string $date, string $seq) {
    $orderNumber = "{$date}/{$seq}";

    return view('pages.checkout.success', compact('orderNumber'));
})->where([
    'date' => '\d{2}-\d{2}-\d{2}',
    'seq' => '\d+',
])->name('checkout.success');

Route::get('/favorites', FavoritesIndex::class)
    ->name('favorites.index');

/*
 * Свёрнутый чат спрашивает, не ответили ли ему. Опознание — по куке,
 * в ответе только число непрочитанного. Потолок щедрый: интервал опроса
 * 20 секунд, то есть три запроса в минуту с вкладки, и упереться в него
 * можно только специально.
 *
 * Сессия маршруту не нужна и снята намеренно: иначе каждый тик писал бы
 * сессию и переставлял куку в браузере — три раза в минуту ради одного
 * числа. Куку чата расшифровывает EncryptCookies, а он остаётся.
 */
Route::get('/chat/unread', ChatUnreadController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        SubstituteBindings::class,
    ])
    ->name('chat.unread');

/*
 * Возврат в чат по ссылке из письма «менеджер ответил»: переписка
 * переезжает на другое устройство вместе с новой кукой. Подпись даёт
 * ссылке срок годности, throttle — защиту от перебора токенов.
 *
 * Ниже /chat/unread намеренно: иначе слово «unread» уедет в {token}.
 */
Route::get('/chat/{token}', ChatResumeController::class)
    ->middleware(['signed', 'throttle:20,1'])
    ->name('chat.resume');

/*
 * Продление сессии и свежий CSRF-токен одним запросом.
 *
 * Страницы магазина держат открытыми часами, а сессия живёт два. Умершая
 * сессия — это 419 на первое же действие, и Livewire отвечает на него
 * английским «This page has expired»; с чатом это случалось бы в ответ
 * на вопрос покупателя. Токен возвращается не для удобства: если сессия
 * успела умереть, этот запрос заводит НОВУЮ, а с ней и новый токен, и
 * страница со старым всё равно получила бы 419. Отдавать токен наружу
 * безопасно: он и так лежит в разметке, а прочитать ответ с чужого origin
 * браузер не даст.
 */
Route::get('/session/keepalive', fn () => response()
    ->json(['token' => csrf_token()])
    ->header('Cache-Control', 'no-store, private'))
    ->middleware('throttle:60,1')
    ->name('session.keepalive');

Route::prefix('user')->middleware(['auth', EnsureStorefrontCustomer::class])->group(function (): void {
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
