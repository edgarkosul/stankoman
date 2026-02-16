<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\QueuesContentImageDerivatives;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Support\Products\CategoryFilterImportService;
use App\Support\Products\CategoryFilterTemplateExportService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class EditCategory extends EditRecord
{
    use QueuesContentImageDerivatives;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_webp_derivatives')
                ->label('Сгенерировать WebP')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->disabled(fn () => ! $this->hasAnyContentImages($this->categoryContentValues()))
                ->action(function (): void {
                    $queued = $this->queueContentImageDerivatives($this->categoryContentValues(), false);
                    $this->notifyContentImageDerivativesQueued($queued, false);
                }),
            Action::make('regenerate_webp_derivatives')
                ->label('Перегенерировать WebP (force)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->disabled(fn () => ! $this->hasAnyContentImages($this->categoryContentValues()))
                ->action(function (): void {
                    $queued = $this->queueContentImageDerivatives($this->categoryContentValues(), true);
                    $this->notifyContentImageDerivativesQueued($queued, true);
                }),
            Action::make('view_public')
                ->label('Открыть на сайте')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(
                    fn ($record) => $record
                        ? route('catalog.leaf', ['path' => $record->slug_path])
                        : null
                )
                ->openUrlInNewTab()
                ->visible(fn ($record) => filled($record?->slug)),
            Action::make('export_filter_template')
                ->label('Экспорт шаблона фильтров')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->visible(fn ($record): bool => (bool) $record?->isLeaf())
                ->action(function (): void {
                    $record = $this->record;

                    if (! $record || ! $record->isLeaf()) {
                        Notification::make()
                            ->title('Доступно только для листовой категории')
                            ->danger()
                            ->send();

                        return;
                    }

                    $result = app(CategoryFilterTemplateExportService::class)->export($record);

                    $token = bin2hex(random_bytes(8));
                    $key = "exports/tmp/{$token}.path";
                    Storage::disk('local')->put($key, $result['path']);

                    $url = route('admin.tools.download-export', [
                        'token' => $token,
                        'name' => $result['downloadName'],
                    ]);

                    Notification::make()
                        ->title('Шаблон готов')
                        ->success()
                        ->actions([
                            Action::make('download')
                                ->label('Скачать XLSX')
                                ->button()
                                ->url($url)
                                ->openUrlInNewTab(),
                        ])
                        ->persistent()
                        ->send();
                }),
            Action::make('import_filter_template')
                ->label('Импорт шаблона фильтров')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->visible(fn ($record): bool => (bool) $record?->isLeaf())
                ->form([
                    FileUpload::make('import_file')
                        ->label('Файл шаблона XLSX')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->disk('local')
                        ->directory('imports')
                        ->visibility('private')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $record = $this->record;

                    if (! $record || ! $record->isLeaf()) {
                        Notification::make()
                            ->title('Доступно только для листовой категории')
                            ->danger()
                            ->send();

                        return;
                    }

                    $storedPath = $this->resolveStoredImportPath($data['import_file'] ?? null);

                    if (! $storedPath) {
                        Notification::make()
                            ->title('Файл не выбран')
                            ->danger()
                            ->send();

                        return;
                    }

                    $absPath = Storage::disk('local')->path($storedPath);

                    if (! is_file($absPath)) {
                        Notification::make()
                            ->title('Не удалось прочитать загруженный файл')
                            ->danger()
                            ->send();

                        return;
                    }

                    $run = ImportRun::query()->create([
                        'type' => 'category_filters',
                        'status' => 'pending',
                        'columns' => null,
                        'totals' => null,
                        'source_filename' => basename($storedPath),
                        'stored_path' => $storedPath,
                        'user_id' => Auth::id(),
                        'started_at' => now(),
                    ]);

                    $totals = app(CategoryFilterImportService::class)
                        ->importFromXlsx($run, $record, $absPath, true);

                    $updated = (int) ($totals['updated'] ?? $totals['update'] ?? 0);
                    $skipped = (int) ($totals['skipped'] ?? $totals['same'] ?? 0);
                    $errors = (int) ($totals['error'] ?? 0);

                    $notification = Notification::make()
                        ->title('Импорт шаблона завершён')
                        ->body("Обновлено: {$updated}, пропущено: {$skipped}, ошибок: {$errors}. Запуск #{$run->id}.")
                        ->actions([
                            Action::make('history')
                                ->label('История импортов')
                                ->button()
                                ->url(ImportRunResource::getUrl())
                                ->openUrlInNewTab(),
                        ])
                        ->persistent();

                    if ($errors > 0) {
                        $notification->warning();
                    } else {
                        $notification->success();
                    }

                    $notification->send();
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function categoryContentValues(): array
    {
        $state = $this->form->getState();

        return [
            $state['img'] ?? $this->record?->img,
        ];
    }

    private function resolveStoredImportPath(mixed $state): ?string
    {
        if (is_string($state) && $state !== '') {
            return $state;
        }

        if (is_array($state)) {
            $first = reset($state);

            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return null;
    }
}
