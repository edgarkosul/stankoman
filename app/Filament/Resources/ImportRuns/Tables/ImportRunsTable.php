<?php

namespace App\Filament\Resources\ImportRuns\Tables;

use App\Models\ImportRun;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->alignRight(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'products' => 'Excel товары',
                        'vactool_products' => 'Vactool',
                        default => (string) $state,
                    })
                    ->badge()
                    ->colors([
                        'gray' => 'products',
                        'primary' => 'vactool_products',
                    ])
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'pending' => 'В ожидании',
                        'dry_run' => 'Проверено',
                        'applied' => 'Применено',
                        'failed' => 'Ошибка',
                        default => (string) $state,
                    })
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'dry_run',
                        'success' => 'applied',
                        'danger' => 'failed',
                    ])
                    ->sortable(),

                TextColumn::make('totals.create')
                    ->label('Создастся')
                    ->alignCenter()
                    ->sortable()
                    ->default(fn (ImportRun $record): int => (int) (
                        data_get($record->totals, 'create')
                        ?? data_get($record->totals, 'applied.created')
                        ?? 0
                    ))
                    ->action(
                        Action::make('preview_create')
                            ->label('Показать создаваемые товары')
                            ->modal()
                            ->modalHeading(fn (ImportRun $record): string => 'Создастся: '.((int) (
                                data_get($record->totals, 'create')
                                ?? data_get($record->totals, 'applied.created')
                                ?? 0
                            )))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Закрыть')
                            ->modalContent(fn (ImportRun $record) => view(
                                'filament.import-runs.preview-list',
                                [
                                    'title' => 'Товары, которые будут созданы',
                                    'rows' => data_get($record->totals, '_preview.create', []),
                                ]
                            ))
                    ),

                TextColumn::make('totals.update')
                    ->label('Обновится')
                    ->alignCenter()
                    ->sortable()
                    ->default(fn (ImportRun $record): int => (int) (
                        data_get($record->totals, 'update')
                        ?? data_get($record->totals, 'applied.updated')
                        ?? 0
                    ))
                    ->action(
                        Action::make('preview_update')
                            ->label('Показать обновляемые товары')
                            ->modal()
                            ->modalHeading(fn (ImportRun $record): string => 'Обновится: '.((int) (
                                data_get($record->totals, 'update')
                                ?? data_get($record->totals, 'applied.updated')
                                ?? 0
                            )))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Закрыть')
                            ->modalContent(fn (ImportRun $record) => view(
                                'filament.import-runs.preview-list',
                                [
                                    'title' => 'Товары, которые будут обновлены',
                                    'rows' => data_get($record->totals, '_preview.update', []),
                                ]
                            ))
                    ),

                TextColumn::make('totals.conflict')
                    ->label('Конфликтов')
                    ->alignCenter()
                    ->sortable()
                    ->default(fn (ImportRun $record): int => (int) (
                        data_get($record->totals, 'conflict')
                        ?? data_get($record->totals, 'applied.conflict')
                        ?? 0
                    ))
                    ->action(
                        Action::make('preview_conflict')
                            ->label('Показать конфликты')
                            ->modal()
                            ->modalHeading(fn (ImportRun $record): string => 'Конфликтов: '.((int) (
                                data_get($record->totals, 'conflict')
                                ?? data_get($record->totals, 'applied.conflict')
                                ?? 0
                            )))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Закрыть')
                            ->modalContent(fn (ImportRun $record) => view(
                                'filament.import-runs.preview-list',
                                [
                                    'title' => 'Строки с конфликтом updated_at',
                                    'rows' => data_get($record->totals, '_preview.conflict', []),
                                ]
                            ))
                    ),

                TextColumn::make('totals.error')
                    ->label('Ошибок')
                    ->alignCenter()
                    ->sortable()
                    ->default(fn (ImportRun $record): int => (int) (
                        data_get($record->totals, 'error')
                        ?? data_get($record->totals, 'applied.error')
                        ?? 0
                    )),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('download_source')
                    ->label('Скачать файл')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ImportRun $record): bool => ! empty($record->stored_path))
                    ->url(fn (ImportRun $record): string => route('admin.tools.download-import', [
                        'run' => $record,
                    ]))
                    ->openUrlInNewTab(),

                Action::make('view_issues')
                    ->label('Показать все проблемы')
                    ->icon('heroicon-o-exclamation-circle')
                    ->modal()
                    ->modalHeading(fn (ImportRun $record): string => "Проблемы для запуска #{$record->id}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalContent(function (ImportRun $record) {
                        $issues = $record->issues()
                            ->orderBy('row_index')
                            ->orderBy('id')
                            ->get();

                        return view('filament.import-runs.issues-list', [
                            'issues' => $issues,
                        ]);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
