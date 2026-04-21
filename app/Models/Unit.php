<?php

namespace App\Models;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute as EloquentAttribute;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = [
        'name',
        'symbol',
        'dimension',
        'base_symbol',
        'si_factor',
        'si_offset',
    ];

    protected $casts = [
        'si_factor' => 'float',
        'si_offset' => 'float',
    ];

    protected static function booted(): void
    {
        static::saved(function (): void {
            NameNormalizer::flushCache();
        });

        static::deleted(function (): void {
            NameNormalizer::flushCache();
        });
    }

    /**
     * Человекопонятные названия семейств единиц.
     */
    public const DIMENSION_LABELS = [
        'length' => 'Длина (мм, см, м, дюймы)',
        'area' => 'Площадь (мм², см², м²)',
        'area_rate' => 'Площадь/время (м²/ч, м²/с)',
        'volume' => 'Объём (л, мл, м³)',
        'flow' => 'Производительность (л/мин, м³/ч, м³/с)',
        'mass' => 'Масса (г, кг, т)',
        'pressure' => 'Давление (Па, бар, атм, кПа, МПа)',
        'power' => 'Мощность (Вт, кВт, л.с.)',
        'voltage' => 'Напряжение (В)',
        'current' => 'Ток (А)',
        'frequency' => 'Частота (Гц, об/мин, уд/мин)',
        'speed' => 'Скорость (мм/с, м/с)',
        'temperature' => 'Температура (°C)',
        'force' => 'Сила (Н, кН, кгс)',
        'torque' => 'Момент силы (Н·м)',
        'energy' => 'Энергия (Дж)',
        'charge' => 'Электрический заряд (А·ч, C)',
        'dimensionless' => 'Безразмерная величина (% , шт)',
        'angle' => 'Угол (°)',
        'time' => 'Время (с, мин, ч)',
    ];

    /**
     * Какой base_symbol подходит как СИ-база для каждого измерения.
     */
    public const DIMENSION_BASE_SYMBOLS = [
        'length' => ['m'],
        'area' => ['m²'],
        'area_rate' => ['m²/s'],
        'volume' => ['m³'],
        'flow' => ['m³/s'],
        'mass' => ['kg'],
        'pressure' => ['Pa'],
        'power' => ['W'],
        'voltage' => ['V'],
        'current' => ['A'],
        'frequency' => ['Hz'],
        'speed' => ['m/s'],
        'temperature' => ['K'],
        'force' => ['N'],
        'torque' => ['N·m'],
        'energy' => ['J'],
        'charge' => ['C'],
        'dimensionless' => ['1'],
        'angle' => ['rad'],
        'time' => ['s'],
    ];

    /** Для селекта/фильтра измерений (полный список). */
    public static function dimensionOptions(): array
    {
        $existingDimensions = self::query()
            ->whereNotNull('dimension')
            ->where('dimension', '!=', '')
            ->distinct()
            ->orderBy('dimension')
            ->pluck('dimension')
            ->all();

        return collect(array_unique([
            ...array_keys(self::DIMENSION_LABELS),
            ...$existingDimensions,
        ]))
            ->mapWithKeys(fn (string $dimension): array => [
                $dimension => self::dimensionLabelFor($dimension),
            ])
            ->all();
    }

    /**
     * Какие варианты base_symbol показывать для выбранного измерения.
     */
    public static function baseSymbolOptions(?string $dimension): array
    {
        $labels = [
            'm' => 'метр (m)',
            'm²' => 'квадратный метр (m²)',
            'm³' => 'кубический метр (m³)',
            'kg' => 'килограмм (kg)',
            's' => 'секунда (s)',
            'A' => 'ампер (A)',
            'K' => 'кельвин (K)',
            'mol' => 'моль (mol)',
            'cd' => 'кандела (cd)',
            'Hz' => 'герц (Hz)',
            'Pa' => 'паскаль (Pa)',
            'N' => 'ньютон (N)',
            'J' => 'джоуль (J)',
            'W' => 'ватт (W)',
            'V' => 'вольт (V)',
            'C' => 'кулон (C)',
            '1' => 'безразмерная (1)',
            'm³/s' => 'м³/с (m³/s)',
            'm²/s' => 'м²/с (m²/s)',
            'rad' => 'радиан (rad)',
            'N·m' => 'ньютон-метр (N·m)',
        ];

        $existingSymbols = self::query()
            ->when(
                filled($dimension),
                fn ($query) => $query->where('dimension', $dimension),
            )
            ->whereNotNull('base_symbol')
            ->where('base_symbol', '!=', '')
            ->distinct()
            ->orderBy('base_symbol')
            ->pluck('base_symbol')
            ->all();

        if (! $dimension || ! isset(self::DIMENSION_BASE_SYMBOLS[$dimension])) {
            return collect(array_unique([
                ...array_keys($labels),
                ...$existingSymbols,
            ]))
                ->mapWithKeys(fn (string $symbol): array => [$symbol => $labels[$symbol] ?? $symbol])
                ->all();
        }

        $symbols = array_unique([
            ...self::DIMENSION_BASE_SYMBOLS[$dimension],
            ...$existingSymbols,
        ]);

        return collect($symbols)
            ->mapWithKeys(fn (string $symbol) => [$symbol => $labels[$symbol] ?? $symbol])
            ->toArray();
    }

    public static function dimensionLabelFor(?string $dimension): string
    {
        if (blank($dimension)) {
            return '—';
        }

        return self::DIMENSION_LABELS[$dimension] ?? $dimension;
    }

    /** Для фильтра по базе — просто distinct из БД. */
    public static function baseSymbolFilterOptions(): array
    {
        return self::query()
            ->whereNotNull('base_symbol')
            ->orderBy('base_symbol')
            ->pluck('base_symbol', 'base_symbol')
            ->toArray();
    }

    /** Аксессор для колонки dimension_label в таблице. */
    protected function dimensionLabel(): EloquentAttribute
    {
        return EloquentAttribute::make(
            get: fn ($value, array $attributes) => self::dimensionLabelFor($attributes['dimension'] ?? null),
        );
    }
}
