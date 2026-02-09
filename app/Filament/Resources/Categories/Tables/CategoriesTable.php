<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Models\Category;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\Categories\CategoryResource;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // ->modifyQueryUsing(function (Builder $query, $livewire) {
            //     $selected = $livewire->selectedCategoryId ?? null;

            //     if ($selected === -1) {
            //         $query->where('parent_id', -1);
            //         return;
            //     }

            //     if ($selected) {
            //         $query->where('parent_id', $selected);
            //         return;
            //     }

            //     $query->where('parent_id', -1);
            // })
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
                    ->url(fn($livewire) => CategoryResource::getUrl('create', [
                        'parent_id' => $livewire->selectedCategoryId ?? -1,
                    ]))
                    ->color('primary'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('openOnSite')
                    ->label('Открыть на сайте')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn(Category $record) => route('catalog.leaf', ['path' => $record->slug_path]))
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
