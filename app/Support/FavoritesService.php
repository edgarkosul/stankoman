<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class FavoritesService
{
    public const COOKIE_KEY = 'favorites_ids';

    private const LEGACY_COOKIE_KEY = 'favorites.ids';

    public const MAX_ITEMS = 200;

    public const COOKIE_TTL_MINUTES = 60 * 24 * 180;

    /**
     * @var int[]|null
     */
    private ?array $runtimeIds = null;

    /**
     * @return int[]
     */
    public function ids(): array
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User) {
                return $user->favoriteProducts()
                    ->pluck('products.id')
                    ->map(fn ($value): int => (int) $value)
                    ->all();
            }
        }

        if ($this->runtimeIds !== null) {
            return $this->runtimeIds;
        }

        return $this->runtimeIds = $this->cookieIds();
    }

    public function count(): int
    {
        return count($this->ids());
    }

    public function contains(int $productId): bool
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User) {
                return $user->favoriteProducts()
                    ->where('products.id', $productId)
                    ->exists();
            }
        }

        return in_array($productId, $this->ids(), true);
    }

    /**
     * @return int[]
     */
    public function add(int $productId): array
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User) {
                $user->favoriteProducts()->syncWithoutDetaching([$productId]);

                return $this->ids();
            }
        }

        $ids = $this->ids();

        if (! in_array($productId, $ids, true)) {
            $ids[] = $productId;
            $this->persistCookieAndCache($ids);
        }

        return $this->ids();
    }

    /**
     * @return int[]
     */
    public function remove(int $productId): array
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User) {
                $user->favoriteProducts()->detach($productId);

                return $this->ids();
            }
        }

        $ids = array_values(array_filter($this->ids(), fn (int $id): bool => $id !== $productId));
        $this->persistCookieAndCache($ids);

        return $ids;
    }

    /**
     * @return int[]
     */
    public function toggle(int $productId): array
    {
        return $this->contains($productId)
            ? $this->remove($productId)
            : $this->add($productId);
    }

    /**
     * @return int[]
     */
    public function clear(): array
    {
        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User) {
                $user->favoriteProducts()->detach();
            }
        }

        $this->persistCookieAndCache([]);

        return [];
    }

    public function syncOnLogin(?Authenticatable $user = null): void
    {
        $resolvedUser = $user instanceof User ? $user : Auth::user();

        if (! $resolvedUser instanceof User) {
            return;
        }

        $cookieIds = $this->cookieIds();

        if ($cookieIds !== []) {
            $resolvedUser->favoriteProducts()->syncWithoutDetaching($cookieIds);
            $this->persistCookieAndCache([]);
        }
    }

    public function syncOnLogout(?Authenticatable $user = null): void
    {
        $resolvedUser = $user instanceof User ? $user : Auth::user();

        if (! $resolvedUser instanceof User) {
            return;
        }

        $ids = $resolvedUser->favoriteProducts()
            ->pluck('products.id')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $this->persistCookieAndCache($ids);
    }

    /**
     * @return int[]
     */
    private function cookieIds(): array
    {
        $raw = Cookie::get(self::COOKIE_KEY);

        if (! $raw) {
            $raw = request()->cookies->get(self::LEGACY_COOKIE_KEY)
                ?? request()->cookies->get(str_replace('.', '_', self::LEGACY_COOKIE_KEY));
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalize($decoded);
    }

    /**
     * @param  int[]  $ids
     */
    private function persistCookieAndCache(array $ids): void
    {
        $ids = $this->normalize($ids);
        $this->runtimeIds = $ids;

        $path = config('session.path', '/');
        $domain = config('session.domain');
        $secure = (bool) config('session.secure', request()->isSecure());
        $httpOnly = true;
        $sameSite = config('session.same_site', 'lax');

        Cookie::queue(cookie(
            self::COOKIE_KEY,
            json_encode($ids, JSON_UNESCAPED_UNICODE),
            self::COOKIE_TTL_MINUTES,
            $path,
            $domain,
            $secure,
            $httpOnly,
            false,
            $sameSite
        ));

        Cookie::queue(cookie(
            self::LEGACY_COOKIE_KEY,
            '',
            -5_256_000,
            $path,
            $domain,
            $secure,
            $httpOnly,
            false,
            $sameSite
        ));
    }

    /**
     * @param  array<int|string>  $ids
     * @return int[]
     */
    private function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (count($ids) > self::MAX_ITEMS) {
            $ids = array_slice($ids, -self::MAX_ITEMS);
        }

        return $ids;
    }
}
