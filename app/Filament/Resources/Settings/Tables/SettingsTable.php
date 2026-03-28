<?php

namespace App\Filament\Resources\Settings\Tables;

use App\Models\Setting;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SettingsTable
{
    private const PRIORITY_ORDER = [
        'general.shop_name',
        'general.manager_emails',
        'general.filament_admin_emails',
        'mail.from.address',
        'company.public_email',
        'company.phone',
        'company.site_url',
        'company.site_host',
        'company.brand_line',
        'product.stavka_nds',
        'company.legal_name',
        'company.inn',
        'company.ogrn',
        'company.ogrnip',
        'company.legal_addr',
        'company.correspondence_addr',
        'company.bank.name',
        'company.bank.bik',
        'company.bank.rs',
        'company.bank.ks',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state, Setting $record): string => $record->translated_key),

                TextColumn::make('value')
                    ->label('Значение')
                    ->limit(60)
                    ->tooltip(fn (Setting $record): string => (string) $record->value),
            ])
            ->defaultSort(fn (Builder $query): Builder => self::applyPrioritySort($query))
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    protected static function applyPrioritySort(Builder $query): Builder
    {
        $bindings = [];
        $caseSegments = [];

        foreach (self::PRIORITY_ORDER as $index => $key) {
            $caseSegments[] = "WHEN ? THEN {$index}";
            $bindings[] = $key;
        }

        $bindings[] = count(self::PRIORITY_ORDER);

        return $query
            ->orderByRaw(
                'CASE `key` '.implode(' ', $caseSegments).' ELSE ? END',
                $bindings,
            )
            ->orderBy('key');
    }
}
