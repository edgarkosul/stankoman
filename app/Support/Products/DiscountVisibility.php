<?php

namespace App\Support\Products;

use Illuminate\Support\Facades\Auth;

/**
 * Цена со скидкой — только для зарегистрированных покупателей
 * (решение заказчика от 20.08.2026).
 *
 * Гостю скидку не показываем и в расчёт не берём: и на витрине, и в корзине,
 * и в фиде. В оформлении заказа скидка также включается, если человек ставит
 * галку «завести личный кабинет» — там передаётся $forAuthenticated явно.
 */
final class DiscountVisibility
{
    public static function allowed(): bool
    {
        return Auth::check();
    }

    /** Итоговая цена с учётом того, доступна ли скидка покупателю. */
    public static function finalPriceFor(int $basePrice, ?int $discountPrice, ?bool $allowed = null): int
    {
        return self::isDiscounted($basePrice, $discountPrice, $allowed)
            ? (int) $discountPrice
            : $basePrice;
    }

    public static function isDiscounted(int $basePrice, ?int $discountPrice, ?bool $allowed = null): bool
    {
        if (! ($allowed ?? self::allowed())) {
            return false;
        }

        return $basePrice > 0
            && $discountPrice !== null
            && $discountPrice > 0
            && $discountPrice < $basePrice;
    }
}
