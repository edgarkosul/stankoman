<?php

use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;

/*
 * Потолок частоты на эндпоинте Livewire.
 *
 * Тест сторожевой: сломать это можно молча. Путь эндпоинта с четвёртой
 * версии Livewire считается от APP_KEY (`/livewire-<хэш>/update`), и свой
 * литерал в setUpdateRoute завёл бы ВТОРОЙ маршрут рядом с настоящим —
 * сайт работал бы, а потолка не было бы ни на одном из них.
 */

it('на эндпоинте Livewire стоит потолок частоты, и маршрут ровно один', function (): void {
    $path = ltrim(EndpointResolver::updatePath(), '/');

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->uri() === $path && in_array('POST', $route->methods(), true));

    expect($routes)->toHaveCount(1);

    $route = $routes->first();

    expect($route->getName())->toBe('livewire.update')
        ->and($route->gatherMiddleware())->toContain('web')
        ->and($route->gatherMiddleware())->toContain('throttle:120,1')
        // Проверку заголовка Livewire дописывает сам — без неё эндпоинт
        // отвечал бы на обычный POST из браузера.
        ->and($route->gatherMiddleware())
        ->toContain(RequireLivewireHeaders::class);
});
