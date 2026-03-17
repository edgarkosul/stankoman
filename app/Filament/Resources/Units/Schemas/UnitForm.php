<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->label('Название'),

                TextInput::make('symbol')
                    ->required()
                    ->label('Символ'),

                // новое поле — семейство единиц
                Select::make('dimension')
                    ->label('Измерение (семейство единиц)')
                    ->options(fn (Get $get): array => self::dimensionOptionsWithCurrentValue($get('dimension')))
                    ->placeholder('— не указано —')
                    ->selectablePlaceholder()
                    ->searchable()
                    ->native(false)
                    ->nullable()
                    ->helperText('Определяет тип величины: длина, объём, расход, давление и т.п. По нему подбираются совместимые единицы и базовая СИ-единица.')
                    ->live()
                    ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? Unit::dimensionLabelFor((string) $value) : null)
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Название семейства')
                            ->required()
                            ->helperText('Например: Диапазон затемнения сварочных масок.')
                            ->maxLength(150),
                    ])
                    ->createOptionUsing(function (array $data): string {
                        return trim((string) ($data['name'] ?? ''));
                    }),

                TextInput::make('si_factor')
                    ->helperText('Коэффициент пересчёта в базовую единицу СИ (например, литры → м³ = 0.001).')
                    ->numeric()
                    ->rule('decimal:0,12')
                    ->default(1)
                    ->label('Коэффициент'),

                // Базовая СИ-единица, завязанная на dimension
                Select::make('base_symbol')
                    ->label('Базовая единица (СИ)')
                    ->options(function (Get $get) {
                        $dimension = $get('dimension');
                        $currentValue = $get('base_symbol');

                        return [
                            '' => '— не указано —',
                        ] + self::baseSymbolOptionsWithCurrentValue($dimension, $currentValue);
                    })
                    ->placeholder('— не указано —')
                    ->selectablePlaceholder()
                    ->native(false)
                    ->nullable()
                    ->searchable()
                    ->preload()
                    ->helperText('Базовая единица, в которую пересчитываются все значения (например, м³ для литров, Па для бар, W для кВт). Для несовместимой пары измерение/база выбрать не получится.')
                    ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (string) $value : null)
                    ->createOptionForm([
                        TextInput::make('value')
                            ->label('Обозначение базовой единицы')
                            ->required()
                            ->helperText('Например: DIN, Pa, m³/s.')
                            ->maxLength(50),
                    ])
                    ->createOptionUsing(function (array $data): string {
                        return trim((string) ($data['value'] ?? ''));
                    }),
            ]);
    }

    protected static function dimensionOptionsWithCurrentValue(mixed $currentValue): array
    {
        $options = Unit::dimensionOptions();

        if (filled($currentValue) && ! array_key_exists((string) $currentValue, $options)) {
            $options[(string) $currentValue] = Unit::dimensionLabelFor((string) $currentValue);
        }

        return $options;
    }

    protected static function baseSymbolOptionsWithCurrentValue(?string $dimension, mixed $currentValue): array
    {
        $options = Unit::baseSymbolOptions($dimension);

        if (filled($currentValue) && ! array_key_exists((string) $currentValue, $options)) {
            $options[(string) $currentValue] = (string) $currentValue;
        }

        return $options;
    }
}
