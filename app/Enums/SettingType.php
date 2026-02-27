<?php

namespace App\Enums;

enum SettingType: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Json = 'json';

    public function label(): string
    {
        return match ($this) {
            self::String => 'Строка',
            self::Int => 'Целое число',
            self::Float => 'Число с плавающей точкой',
            self::Bool => 'Логическое значение',
            self::Json => 'JSON',
        };
    }
}
