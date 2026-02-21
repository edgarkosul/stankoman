<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $title = 'Категории';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Категория')
                    ->searchable()
                    // клик по названию — в редактирование категории в админке
                    ->url(fn ($record) => CategoryResource::getUrl('edit', ['record' => $record])),
                IconColumn::make('pivot.is_primary')
                    ->label('Основная')
                    ->boolean(),
                // ImageColumn::make('img'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make('attachCategory')
                    ->label('Привязать категорию')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Привязать категорию к товару')
                    ->modalSubmitActionLabel('Привязать')
                    ->attachAnother(false)
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'slug'])
                    ->recordSelectOptionsQuery(
                        fn (Builder $query): Builder => $query
                            ->leaf()
                            ->orderBy('name')
                    )
                    ->recordSelect(
                        fn (Select $select): Select => $select
                            ->label('Категория')
                    )
                    ->after(function (): void {
                        $this->getOwnerRecord()->unsetRelation('categories');
                    })
                    ->successNotificationTitle('Категория привязана'),
            ])
            ->recordActions([
                Action::make('setPrimary')
                    ->label('Сделать основной')
                    ->icon('heroicon-o-star')
                    ->visible(fn ($record) => ! $record->isBrandCategory()) // запрет для брендовых
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
                    ->url(fn ($record) => route('catalog.leaf', ['path' => $record->slug_path])) // если имя роута другое — поменяй тут
                    ->openUrlInNewTab(),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),

                ]),
            ]);
    }
}
