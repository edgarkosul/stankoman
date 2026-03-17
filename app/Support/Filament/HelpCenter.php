<?php

namespace App\Support\Filament;

class HelpCenter
{
    /**
     * @return array<string, string>
     */
    public static function routes(): array
    {
        /** @var array<string, string> $routes */
        $routes = config('filament-help.routes', []);

        return $routes;
    }

    public static function urlForRouteName(?string $routeName): ?string
    {
        if (! is_string($routeName) || $routeName === '') {
            return null;
        }

        return static::routes()[$routeName] ?? null;
    }

    public static function urlForCurrentRoute(): ?string
    {
        return static::urlForRouteName(request()->route()?->getName());
    }
}
