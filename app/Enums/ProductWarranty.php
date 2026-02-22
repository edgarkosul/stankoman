<?php

namespace App\Enums;

enum ProductWarranty: string
{
    case Months12 = '12';
    case Months24 = '24';
    case Months36 = '36';
    case Months60 = '60';

    public function label(): string
    {
        return $this->value.' мес.';
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Months12->value => self::Months12->label(),
            self::Months24->value => self::Months24->label(),
            self::Months36->value => self::Months36->label(),
            self::Months60->value => self::Months60->label(),
        ];
    }
}
