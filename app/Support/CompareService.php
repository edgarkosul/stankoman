<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;

class CompareService
{
    public const SESSION_KEY = 'compare.ids';

    public const MAX_ITEMS = 10;

    /**
     * @return int[]
     */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', Session::get(self::SESSION_KEY, []))));
    }

    public function count(): int
    {
        return count($this->ids());
    }

    public function contains(int $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    /**
     * @return int[]
     */
    public function add(int $productId): array
    {
        $ids = $this->ids();

        if (! in_array($productId, $ids, true)) {
            if (count($ids) >= self::MAX_ITEMS) {
                $ids = array_slice($ids, -(self::MAX_ITEMS - 1));
            }

            $ids[] = $productId;
            Session::put(self::SESSION_KEY, $ids);
        }

        return $ids;
    }

    /**
     * @return int[]
     */
    public function remove(int $productId): array
    {
        $ids = array_values(array_filter($this->ids(), fn (int $id): bool => $id !== $productId));
        Session::put(self::SESSION_KEY, $ids);

        return $ids;
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public function toggle(int $productId): bool
    {
        if ($this->contains($productId)) {
            $this->remove($productId);

            return false;
        }

        $this->add($productId);

        return true;
    }

    public function isInCompare(int $productId): bool
    {
        return $this->contains($productId);
    }
}
