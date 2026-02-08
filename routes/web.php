<?php

use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Livewire\Pages\Categories\LeafCategoryPage;
use App\Models\Page;
use Illuminate\Support\Facades\Route;

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

require __DIR__.'/settings.php';
