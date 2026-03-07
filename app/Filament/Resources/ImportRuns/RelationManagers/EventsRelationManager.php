<?php

namespace App\Filament\Resources\ImportRuns\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use App\Models\ImportRun;
use App\Models\ImportRunEvent;
use App\Models\Product;
use App\Support\CatalogImport\Runs\ImportRunEventLabels;
use App\Support\CatalogImport\Runs\ImportRunEventsExportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Детальный лог импорта';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('stage')
                    ->label('Этап')
                    ->formatStateUsing(fn (?string $state): string => ImportRunEventLabels::stageLabel($state))
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('result')
                    ->label('Результат')
                    ->formatStateUsing(fn (?string $state): string => ImportRunEventLabels::resultLabel($state))
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Код')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('message')
                    ->label('Сообщение')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('external_id')
                    ->label('Внешний ID')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('product_id')
                    ->label('ID товара')
                    ->url(
                        fn (ImportRunEvent $record): ?string => filled($record->product_id)
                            ? ProductResource::getUrl('edit', [
                                'record' => Product::query()->whereKey($record->product_id)->value('slug'),
                            ])
                            : null
                    )
                    ->openUrlInNewTab()
                    ->toggleable()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('source_ref')
                    ->label('Источник')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('source_category_id')
                    ->label('Source category')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->label('Этап')
                    ->options(ImportRunEventLabels::stageOptions()),
                SelectFilter::make('result')
                    ->label('Результат')
                    ->options(ImportRunEventLabels::resultOptions()),
                Filter::make('only_errors')
                    ->label('Только ошибки')
                    ->query(fn (Builder $query): Builder => $query->whereIn('result', ['error', 'fatal'])),
            ])
            ->headerActions([
                Action::make('export_xlsx')
                    ->label('Скачать XLSX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (): void {
                        $query = $this->getFilteredSortedTableQuery();

                        if (! $query instanceof Builder) {
                            Notification::make()
                                ->title('Экспорт недоступен')
                                ->body('Не удалось собрать выборку для выгрузки.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof ImportRun) {
                            return;
                        }

                        $result = app(ImportRunEventsExportService::class)->exportToXlsx(
                            query: clone $query,
                            runId: (int) $owner->id,
                        );

                        $token = bin2hex(random_bytes(8));
                        $key = "exports/tmp/{$token}.path";

                        Storage::disk('local')->put($key, $result['path']);

                        Notification::make()
                            ->title('Файл готов')
                            ->success()
                            ->actions([
                                Action::make('download')
                                    ->label('Скачать XLSX')
                                    ->button()
                                    ->url(route('admin.tools.download-export', [
                                        'token' => $token,
                                        'name' => $result['downloadName'],
                                    ]))
                                    ->openUrlInNewTab(),
                            ])
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('show_context')
                    ->label('Контекст')
                    ->icon('heroicon-o-code-bracket')
                    ->modal()
                    ->modalHeading(fn (ImportRunEvent $record): string => "Контекст события #{$record->id}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть')
                    ->modalContent(function (ImportRunEvent $record) {
                        $context = null;
                        $json = null;

                        if (is_array($record->context) && $record->context !== []) {
                            $context = $record->context;
                            $encoded = json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $json = $encoded === false ? null : $encoded;
                        }

                        return view('filament.import-runs.event-context', [
                            'context' => $context,
                            'json' => $json,
                        ]);
                    }),
            ]);
    }
}
