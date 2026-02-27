<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait ResolvesAuthRedirectTarget
{
    protected function resolveRedirectTarget(?string $fallback = null): string
    {
        $target = $this->normalizeTarget(session()->pull('url.intended'))
            ?? $this->normalizeTarget(request()->headers->get('Referer'));

        return $target ?? $fallback ?? route('home', absolute: false);
    }

    protected function normalizeTarget(mixed $target): ?string
    {
        if (! is_string($target) || $target === '') {
            return null;
        }

        if (str_starts_with($target, '/')) {
            return $target;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $targetBase = rtrim($target, '/');

        if ($appUrl !== '' && str_starts_with($targetBase, $appUrl)) {
            $path = Str::after($target, $appUrl);

            return '/'.ltrim((string) $path, '/');
        }

        return null;
    }
}
