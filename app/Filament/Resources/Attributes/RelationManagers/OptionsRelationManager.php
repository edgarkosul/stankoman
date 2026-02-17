<?php

namespace App\Filament\Resources\Attributes\RelationManagers;

use App\Support\FilterSchemaCache;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'options';

    protected static ?string $title = 'Опции доступные для использования';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->usesOptions();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('value')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->afterReordering(function (): void {
                $this->invalidateOwnerAttributeSchemaCache();
            })
            ->recordTitleAttribute('value')
            ->columns([
                TextColumn::make('value')
                    ->label('Значение')
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->sortable()
                    ->label('Порядок'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (): void {
                        $this->invalidateOwnerAttributeSchemaCache();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (): void {
                        $this->invalidateOwnerAttributeSchemaCache();
                    }),
                DeleteAction::make()
                    ->after(function (): void {
                        $this->invalidateOwnerAttributeSchemaCache();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(function (): void {
                            $this->invalidateOwnerAttributeSchemaCache();
                        }),
                ]),
            ]);
    }

    private function invalidateOwnerAttributeSchemaCache(): void
    {
        $attributeId = (int) ($this->getOwnerRecord()?->getKey() ?? 0);

        if ($attributeId > 0) {
            FilterSchemaCache::forgetByAttribute($attributeId);
        }
    }
}
