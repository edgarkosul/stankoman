<?php

namespace App\Filament\Resources\Attributes\RelationManagers;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Support\FilterSchemaCache;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DBSchema;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $title = 'Категории (использование)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->searchable(),
                TextColumn::make('name')->label('Категория')->searchable()->url(fn ($record) => CategoryResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->paginated(false)
            ->headerActions([
                Action::make('attachCategory')
                    ->label('Привязать категорию')
                    ->icon('heroicon-m-plus')
                    ->modalHeading('Привязать категорию к атрибуту')
                    ->modalSubmitActionLabel('Привязать')
                    ->schema([
                        Select::make('recordId')
                            ->label('Категория')
                            ->searchable()
                            ->preload()
                            ->options(function () {
                                $attributeId = $this->getOwnerRecord()->getKey();

                                return Category::query()
                                    ->when(
                                        DBSchema::hasColumn('categories', 'is_leaf'),
                                        fn (Builder $q) => $q->where('is_leaf', true)
                                    )
                                    ->whereDoesntHave('attributeDefs', fn (Builder $q) => $q->whereKey($attributeId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $categoryId = (int) ($data['recordId'] ?? 0);
                        if ($categoryId <= 0) {
                            return;
                        }

                        $this->getRelationship()->attach($categoryId);
                        FilterSchemaCache::forgetCategory($categoryId);
                        $this->dispatch('attribute-updated');

                    })
                    ->successNotificationTitle('Категория привязана'),
            ])
            ->recordActions([
                Action::make('detachCategory')
                    ->label('Отвязать')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Отвязать эту категорию от атрибута?')
                    ->action(function (\App\Models\Category $record): void {
                        $categoryId = (int) $record->getKey();

                        $this->getRelationship()->detach($categoryId);
                        FilterSchemaCache::forgetCategory($categoryId);
                        $this->dispatch('attribute-updated');

                    })
                    ->successNotificationTitle('Категория отвязана'),
            ]); // read-only
    }
}
