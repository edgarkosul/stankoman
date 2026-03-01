<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Awaiting = 'awaiting';
    case Paid = 'paid';

    public function label(): string
    {
        return __('order.payment.'.$this->value);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
