<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/page/{page:slug}', PageController::class)
    ->name('page.show');

require __DIR__.'/settings.php';

