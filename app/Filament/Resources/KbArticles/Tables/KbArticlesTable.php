<?php

namespace App\Filament\Resources\KbArticles\Tables;

use App\Models\KbArticle;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KbArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('category'))
            /*
             * Пустой список — первое, что видит новый человек в разделе,
             * и объяснять назначение раздела надо именно здесь: в момент,
             * когда он ищет, с чего начать, а не в документе, который
             * откроет потом.
             */
            ->emptyStateHeading('Статей пока нет')
            ->emptyStateDescription('Здесь живут ответы, которых нет на сайте: бот читает их наравне со страницами магазина. Обычно статьи заводят не отсюда, а с экрана «Пробелы» — там видно, о чём покупатели спрашивают, а бот не отвечает.')
            ->columns([
                TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('category.name')
                    ->label('Раздел')
                    ->badge()
                    ->placeholder('без раздела')
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label('Опубликована')
                    ->boolean(),

                /*
                 * Главная колонка этого экрана. Переиндексация идёт в очереди
                 * и внешне ничем себя не проявляет: без неё правят текст
                 * и не знают, дошла ли правка до бота.
                 */
                TextColumn::make('indexed_at')
                    ->label('Проиндексировано')
                    ->state(fn (KbArticle $record): string => match (true) {
                        ! $record->is_published => 'черновик',
                        $record->indexed_at === null => 'ещё нет',
                        $record->isIndexStale() => 'есть правки после индексации',
                        default => $record->chunks_count.' фрагм., '.$record->indexed_at->diffForHumans(),
                    })
                    ->badge()
                    ->color(fn (KbArticle $record): string => match (true) {
                        ! $record->is_published => 'gray',
                        $record->indexed_at === null || $record->isIndexStale() => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('updated_at')
                    ->label('Изменена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Публикация')
                    ->trueLabel('Опубликованные')
                    ->falseLabel('Черновики')
                    ->placeholder('Все'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
