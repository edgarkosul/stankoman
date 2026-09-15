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
        // Страницы без статьи в справочном центре.
        'filament.admin.pages.pricing-survey',
        'filament.admin.pages.site-exports',
        // Заявки на звонок появились, когда help.stankoman.ru уже не резолвился:
        // писать статью некуда, пока с заказчиком не решено, где живёт справка.
        'filament.admin.resources.callback-requests.index',
        'filament.admin.resources.callback-requests.view',
        // Разделы бота — по той же причине: справки нет и писать её некуда.
        // Как вести бота, объясняют сами экраны — подписями и пустыми состояниями.
        'filament.admin.pages.assistant-sandbox',
        'filament.admin.pages.assistant-settings',
        'filament.admin.pages.kb-gaps',
        'filament.admin.resources.chat-conversations.index',
        'filament.admin.resources.chat-conversations.view',
        'filament.admin.resources.kb-articles.create',
        'filament.admin.resources.kb-articles.edit',
        'filament.admin.resources.kb-articles.index',
        'filament.admin.resources.kb-categories.index',
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
