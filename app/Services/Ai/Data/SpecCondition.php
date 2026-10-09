<?php

namespace App\Services\Ai\Data;

/**
 * Одно условие подбора по характеристике: «производительность не меньше
 * 3 м³/мин», «вес не больше 60 кг».
 *
 * Название характеристики — СПИСОК вариантов, а не одно слово: у разных
 * поставщиков один параметр называется по-разному («Производительность, л/мин»,
 * «Производительность: л/мин», «Объем воздушного потока»), и угадать все
 * с первого раза модель не может. Чего не угадала, ей покажет выдача.
 */
final readonly class SpecCondition
{
    /**
     * @param  list<string>  $names  варианты названия: строка подходит, если в её
     *                               названии есть все слова хотя бы одного варианта
     * @param  string  $unit  в чём покупатель назвал число; пусто — сравнивать как есть
     * @param  bool  $everyNumber  проходить порог обязано КАЖДОЕ число строки, а не хоть
     *                             одно: «400 / 550х360» под заготовку 400×400 не годится
     */
    public function __construct(
        public array $names,
        public ?float $min = null,
        public ?float $max = null,
        public string $unit = '',
        public bool $everyNumber = false,
    ) {}

    /** Условие словами — для подписи выдачи: «производительность ≥ 3 м³/мин». */
    public function label(): string
    {
        $unit = $this->unit !== '' ? ' '.$this->unit : '';
        $bounds = [];

        if ($this->min !== null) {
            $bounds[] = '≥ '.self::number($this->min).$unit;
        }

        if ($this->max !== null) {
            $bounds[] = '≤ '.self::number($this->max).$unit;
        }

        return '«'.implode('» или «', $this->names).'» '.implode(' и ', $bounds)
            .($this->everyNumber ? ' (каждое число строки)' : '');
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }
}
