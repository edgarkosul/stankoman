<?php

namespace App\Filament\Resources\Attributes\RelationManagers;

use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DetachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Toggle;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\RelationManagers\RelationManager;

class ProductsViaValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'productsViaValues';
    protected static ?string $title = 'Продукты (использование)';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('value_text')
            ->columns([
                TextColumn::make('name')
                    ->url(fn($record) => ProductResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('attr_value')
                    ->label('Значение атрибута')
                    ->state(function ($record, RelationManager $livewire) {
                        $attribute = $livewire->getOwnerRecord();
                        if (! $attribute) return null;

                        $data = $record->attr($attribute->id); // теперь отдаёт {"type": "...", "values": [...]}
                        if (!is_array($data) || empty($data['type'])) return null;

                        $type   = $data['type'];
                        $values = $data['values'] ?? [];

                        // Нормализация чисел: убираем хвосты нулей
                        $fmt = function ($n) {
                            if (! is_numeric($n)) {
                                return (string) $n;
                            }

                            $s = (string) $n;

                            // Тримим только если есть десятичная точка
                            if (str_contains($s, '.')) {
                                $s = rtrim($s, '0'); // 220.5000 -> 220.5, 220.0 -> 220.
                                $s = rtrim($s, '.'); // 220. -> 220
                            }

                            return $s; // 220 -> 220 (не станет 22)
                        };

                        // Преобразуем по типам
                        return match ($type) {
                            'text'    => implode(', ', array_map(fn($v) => (string) $v, $values)),
                            'number'  => implode(', ', array_map($fmt, $values)),
                            'boolean' => implode(', ', array_map(fn($v) => $v ? 'Да' : 'Нет', $values)),
                            'range'   => implode(', ', array_map(function ($v) use ($fmt) {
                                if (!is_array($v)) return (string) $v;
                                $min = array_key_exists('min', $v) ? $v['min'] : null;
                                $max = array_key_exists('max', $v) ? $v['max'] : null;
                                return ($min !== null ? $fmt($min) : '—') . '–' . ($max !== null ? $fmt($max) : '—');
                            }, $values)),
                            // на случай usesOptions()
                            'options' => implode(', ', array_map('strval', $data['labels'] ?? [])),
                            default   => json_encode($data, JSON_UNESCAPED_UNICODE),
                        };
                    })
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('slug')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable(),
                TextColumn::make('brand')->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                SelectFilter::make('categories')
                    ->relationship('categories', 'name', fn(Builder $query) => $query->leaf()->orderBy('order'))
                    ->searchable()
                    ->preload()
            ])
            ->headerActions([
                AttachAction::make(),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
