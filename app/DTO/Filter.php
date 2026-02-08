<?php

namespace App\DTO;

use App\Enums\FilterType;

final class Filter
{
    public function __construct(
        public string $key,    // 'attr_5'
        public string $label,  // 'Диаметр электрода'
        public FilterType $type,   // range|boolean|select|multiselect|text
        public array  $meta = [],   // min/max/step/decimals/suffix/options[]
        public int    $order = 0,
        public string $value_cast,
    ) {}

    public function toArray(): array
    {
        return [
            'key'   => $this->key,
            'label' => $this->label,
            'type'  => $this->type->value,
            'meta'  => $this->meta,
            'order' => $this->order,
            'value_cast' => $this->value_cast,
        ];
    }
}
