<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\Select;
use App\Models\Category;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\RelationManagers\RelationManager;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';
    protected static null|string $title = 'Категории';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Категория')
                    ->searchable()
                    // клик по названию — в редактирование категории в админке
                    ->url(fn($record) => CategoryResource::getUrl('edit', ['record' => $record])),
                IconColumn::make('pivot.is_primary')
                    ->label('Основная')
                    ->boolean(),
                // ImageColumn::make('img'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('attachCategory')
                    ->label('Привязать категорию')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Привязать категорию к товару')
                    ->modalSubmitActionLabel('Привязать')
                    ->schema([
                        Select::make('category_id')
                            ->label('Категория')
                            ->searchable()
                            ->preload()
                            ->options(function (): array {
                                $productId = $this->getOwnerRecord()->getKey();

                                return Category::query()
                                    ->leaf()
                                    ->whereDoesntHave('products', fn(Builder $q) => $q->whereKey($productId))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $categoryId = (int) ($data['category_id'] ?? 0);
                        if (! $categoryId) {
                            return;
                        }

                        $product = $this->getOwnerRecord();

                        if (! Category::whereKey($categoryId)->leaf()->exists()) {
                            Notification::make()
                                ->danger()
                                ->title('Товар можно привязывать только к листовой категории.')
                                ->send();
                            return;
                        }

                        $product->categories()
                            ->syncWithoutDetaching([$categoryId => ['is_primary' => false]]);
                        $product->unsetRelation('categories');
                    })
                    ->successNotificationTitle('Категория привязана'),
            ])
            ->recordActions([
                Action::make('setPrimary')
                    ->label('Сделать основной')
                    ->icon('heroicon-o-star')
                    ->visible(fn($record) => ! $record->isBrandCategory()) // запрет для брендовых
                    ->requiresConfirmation()
                    ->action(function ($record, $livewire) {
                        /** @var \App\Models\Product $product */
                        $product = $livewire->getOwnerRecord();

                        if ($record->isBrandCategory()) {
                            Notification::make()
                                ->danger()
                                ->title('Категория с брендами не может быть основной!')
                                ->send();
                            return;
                        }

                        $product->setPrimaryCategory($record->id);

                        Notification::make()
                            ->success()
                            ->title('Основная категория установлена')
                            ->send();
                    }),
                Action::make('detachCategory')
                    ->label('Отвязать')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Отвязать эту категорию от товара?')
                    ->action(function (Category $record): void {
                        $product = $this->getOwnerRecord();
                        $product->categories()->detach($record->getKey());
                        $product->unsetRelation('categories');
                    })
                    ->successNotificationTitle('Категория отвязана'),
                Action::make('openUi')
                    ->label('')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn($record) => route('catalog.leaf', ['path' => $record->slug_path])) // если имя роута другое — поменяй тут
                    ->openUrlInNewTab(),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),

                ]),
            ]);
    }
}
