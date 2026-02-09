<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;

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
                    ->options(Unit::dimensionOptions())
                    ->searchable()
                    ->native(false)
                    ->nullable()
                    ->helperText('Определяет тип величины: длина, объём, расход, давление и т.п. По нему подбираются совместимые единицы и базовая СИ-единица.')
                    ->live(), // чтобы base_symbol реагировал на изменение

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

                        return [
                            '' => '— не указано —',
                        ] + Unit::baseSymbolOptions($dimension);
                    })
                    ->placeholder('— не указано —')
                    ->native(false)
                    ->nullable()
                    ->searchable()
                    ->preload()
                    ->helperText('Базовая единица, в которую пересчитываются все значения (например, м³ для литров, Па для бар, W для кВт). Для несовместимой пары измерение/база выбрать не получится.')
            ]);
    }
}
