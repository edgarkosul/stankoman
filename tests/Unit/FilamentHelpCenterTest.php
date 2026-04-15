<?php

use App\Support\Filament\HelpCenter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

test('help center routes match real admin routes and only omit explicitly unsupported pages', function (): void {
    $filamentRouteNames = collect(Route::getRoutes()->getRoutes())
        ->filter(function ($route): bool {
            $name = $route->getName();
            $action = $route->getActionName();

            return is_string($name)
                && (str_starts_with($name, 'filament.admin.pages.') || str_starts_with($name, 'filament.admin.resources.'))
                && $action !== 'Closure';
        })
        ->map(fn ($route): string => $route->getName())
        ->sort()
        ->values();

    $mappedRouteNames = collect(HelpCenter::routes())
        ->keys()
        ->sort()
        ->values();

    $unsupportedRouteNames = collect([
        'filament.admin.pages.site-exports',
    ])->sort()->values();

    expect($filamentRouteNames->diff($mappedRouteNames)->values())->toEqual($unsupportedRouteNames);
    expect($mappedRouteNames->diff($filamentRouteNames)->values())->toBeEmpty();
});

test('help center returns expected urls for representative filament pages', function (): void {
    expect(HelpCenter::urlForRouteName('filament.admin.pages.product-import-export'))
        ->toBe('https://help.stankoman.ru/import/excel-import/');

    expect(HelpCenter::urlForRouteName('filament.admin.resources.attributes.index'))
        ->toBe('https://help.stankoman.ru/attributes/');

    expect(HelpCenter::urlForRouteName('filament.admin.resources.orders.view'))
        ->toBe('https://help.stankoman.ru/orders/view/');
});

test('help center links always point to production help host', function (): void {
    foreach (HelpCenter::routes() as $url) {
        expect($url)->toStartWith('https://help.stankoman.ru/');
        expect($url)->toEndWith('/');
    }
});
