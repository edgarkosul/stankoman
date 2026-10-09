<?php

namespace App\Services\Ai\Data;

/**
 * Итог подбора по характеристикам — вместе с тем, чего проверить не удалось.
 *
 * Счётчики здесь не для отчёта, а против главной ошибки такого фильтра:
 * товар без нужной характеристики выглядел бы как «не подошёл», и бот
 * сказал бы «нет», когда правильно «по карточке не проверить».
 */
final readonly class SpecMatches
{
    /**
     * @param  list<ProductCard>  $cards  подошедшие, от дешёвых к дорогим; в `specs` —
     *                                    только строки, по которым шла проверка
     * @param  int  $scanned  сколько товаров просмотрено
     * @param  int  $passed  сколько прошли все условия (показаны могут быть не все)
     * @param  int  $failed  сколько не прошли хоть одно
     * @param  list<int>  $noData  по каждому условию: у скольких товаров такой строки нет
     *                             или в ней нет числа нужной единицы
     * @param  list<array{name: string, count: int}>  $names  характеристики с числами,
     *                                                        что есть в просмотренных товарах
     * @param  list<ProductCard>  $nearest  когда не подошло ничего и условие одно —
     *                                      ближайшие к порогу, самые близкие первыми
     */
    public function __construct(
        public array $cards,
        public int $scanned,
        public int $passed,
        public int $failed,
        public array $noData,
        public array $names,
        public array $nearest = [],
    ) {}

    public function unknown(): int
    {
        return $this->scanned - $this->passed - $this->failed;
    }
}
