<?php

use App\Http\Middleware\EnsureStorefrontCustomer;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureStorefrontCustomer::class])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
});

Route::middleware(['auth', 'verified', EnsureStorefrontCustomer::class])->group(function () {
    Route::livewire('settings/password', Password::class)->name('user-password.edit');
});
