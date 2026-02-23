<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutStaging())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable(),
                TextColumn::make('slug')
                    ->label('ЧПУ')
                    ->searchable(),
                ImageColumn::make('img')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('createCategory')
                    ->label('Создать категорию')
                    ->icon('heroicon-m-plus')
                    ->url(fn ($livewire) => CategoryResource::getUrl('create', [
                        'parent_id' => $livewire->selectedCategoryId ?? -1,
                    ]))
                    ->color('primary'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('openOnSite')
                    ->label('Открыть на сайте')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Category $record) => route('catalog.leaf', ['path' => $record->slug_path]))
                    ->openUrlInNewTab()
                    ->tooltip('Откроется в новой вкладке'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
