<?php

if (! function_exists('price')) {
    /**
     * Формат рублёвой суммы: 12 345 ₽
     *
     * @param  int|float|string|null  $amount  сумма в рублях
     * @param  string|null            $symbol  символ валюты (по умолчанию ₽)
     * @return string
     */
    function price(int|float|string|null $amount, ?string $symbol = null): string
    {
        $amount = is_numeric($amount) ? (float) $amount : 0.0;

        // пробел-разделитель тысяч; можно поставить узкий неразрывный "\u{202F}"
        $thousands = "\u{202F}";
        $formatted = number_format($amount, 0, '', $thousands);

        // неразрывный пробел перед символом валюты
        $nbsp = "\u{00A0}";
        $symbol = $symbol ?? '₽';

        return $formatted . $nbsp . $symbol;
    }
}
