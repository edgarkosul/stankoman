<?php

use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Livewire\Pages\Cart\Index as CartIndex;
use App\Livewire\Pages\Categories\LeafCategoryPage;
use App\Livewire\Pages\Compare\Page as ComparePage;
use App\Livewire\Pages\Favorites\Index as FavoritesIndex;
use App\Models\ImportRun;
use App\Models\Page;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    $homePage = Page::query()->where('slug', 'home')->first();

    return view('pages.home', [
        'homePage' => $homePage,
    ]);
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/page/{page:slug}', PageController::class)
    ->name('page.show');

Route::livewire('/catalog/{path?}', LeafCategoryPage::class)
    ->where('path', '.*')
    ->name('catalog.leaf');

Route::get('/product/{product:slug}', [ProductController::class, 'show'])
    ->name('product.show');

Route::get('/compare', ComparePage::class)
    ->name('compare.index');

Route::get('/cart', CartIndex::class)
    ->name('cart.index');

Route::get('/favorites', FavoritesIndex::class)
    ->name('favorites.index');

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

require __DIR__.'/settings.php';
