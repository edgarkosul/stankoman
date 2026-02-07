<?php

use App\Http\Controllers\PageController;
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

require __DIR__.'/settings.php';
