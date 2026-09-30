<?php

use App\Providers\AiSupportServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AiSupportServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    SettingsServiceProvider::class,
];
