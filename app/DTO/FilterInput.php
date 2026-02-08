<?php

namespace App\DTO;

use App\Enums\FilterType;

/**
 * Нормализованный ввод для одного фильтра: значения, диапазоны, булевы флажки.
 */
final class FilterInput
{
    /**
     * @param string          $key           Ключ фильтра.
     * @param FilterType|null $type          Тип фильтра из UI.
     * @param string[]        $values        Список значений (очищенных).
     * @param float|null      $min           Минимум для диапазона.
     * @param float|null      $max           Максимум для диапазона.
     * @param bool|null       $bool          Значение булевого фильтра.
     * @param bool            $hasBoolValue  Был ли передан флажок (отличие false/null).
     */
    public function __construct(
        public string $key,
        public ?FilterType $type,
        public array $values = [],
        public ?float $min = null,
        public ?float $max = null,
        public ?bool $bool = null,
        public bool $hasBoolValue = false,
    ) {}

    public function hasRange(): bool
    {
        return $this->min !== null || $this->max !== null;
    }

    public function hasValues(): bool
    {
        return ! empty($this->values);
    }
}
