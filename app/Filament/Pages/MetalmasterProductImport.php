<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunMetalmasterProductImportJob;
use App\Models\ImportRun;
use BackedEnum;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class MetalmasterProductImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Импорт/Экспорт';

    protected static ?string $navigationLabel = 'Импорт Metalmaster';

    protected static ?string $title = 'Импорт товаров из Metalmaster';

    protected string $view = 'filament.pages.metalmaster-product-import';

    /** @var array{
     *     bucket: string,
     *     buckets_file: string,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int
     * }|null
     */
    public ?array $data = null;

    /** @var array<string, int|string|bool|null>|null */
    public ?array $lastSavedRun = null;

    /** @var array<int, string> */
    public array $lastSavedIssues = [];

    public ?int $lastRunId = null;

    public function mount(): void
    {
        $this->data = $this->defaultData();

        $this->form->fill($this->data);
        $this->refreshLastSavedRun();
    }

    protected function getFormStatePath(): string
    {
        return 'data';
    }

    /**
     * @return array<FormAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            FormAction::make('instructions')
                ->label('Инструкция')
                ->icon('heroicon-o-question-mark-circle')
                ->color('gray')
                ->url(ImportExportHelp::getUrl()),
            FormAction::make('history')
                ->label('История импортов')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(ImportRunResource::getUrl()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Параметры парсинга Metalmaster')
                    ->description('Перед запуском обновите buckets через parser:sitemap-buckets.')
                    ->schema([
                        TextInput::make('buckets_file')
                            ->label('Файл buckets (абсолютный путь)')
                            ->required()
                            ->hintIcon(Heroicon::InformationCircle, 'По умолчанию: storage/app/parser/metalmaster-buckets.json.'),
                        TextInput::make('bucket')
                            ->label('Bucket (пусто = все категории)')
                            ->hintIcon(Heroicon::InformationCircle, 'Например: promyshlennye или svetilniki.'),
                        TextInput::make('limit')
                            ->label('Лимит URL (0 = все)')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('timeout')
                            ->label('Таймаут запроса, сек')
                            ->numeric()
                            ->integer()
                            ->minValue(1),
                        TextInput::make('delay_ms')
                            ->label('Задержка между запросами, мс')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('show_samples')
                            ->label('Примеры строк в dry-run')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        Toggle::make('publish')
                            ->label('Публиковать импортированные товары')
                            ->hintIcon(Heroicon::InformationCircle, 'В write-режиме выставляет признак Показывать на сайте.'),
                        Toggle::make('download_images')
                            ->label('Скачивать изображения')
                            ->hintIcon(Heroicon::InformationCircle, 'Включено по умолчанию: изображения сохраняются в storage/app/public/pics.')
                            ->default(true),
                        Toggle::make('skip_existing')
                            ->label('Пропускать уже существующие товары')
                            ->hintIcon(Heroicon::InformationCircle, 'Если товар найден по slug, он не будет обновлен.'),
                    ]),
                Section::make('Запуск')
                    ->schema([
                        Actions::make([
                            FormAction::make('dry_run')
                                ->label('Запустить dry-run')
                                ->color('success')
                                ->action('doDryRun'),
                            FormAction::make('import')
                                ->label('Импортировать в базу')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('doImport'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function doDryRun(): void
    {
        $this->dispatchImport(false);
    }

    public function doImport(): void
    {
        $this->dispatchImport(true);
    }

    private function dispatchImport(bool $write): void
    {
        $options = $this->buildOptions($write);
        $mode = $write ? 'write' : 'dry-run';

        $run = ImportRun::query()->create([
            'type' => 'metalmaster_products',
            'status' => 'pending',
            'columns' => $options,
            'totals' => [
                'create' => 0,
                'update' => 0,
                'same' => 0,
                'conflict' => 0,
                'error' => 0,
                'scanned' => 0,
                '_meta' => [
                    'mode' => $mode,
                    'found_urls' => 0,
                    'images_downloaded' => 0,
                    'image_download_failed' => 0,
                    'derivatives_queued' => 0,
                    'no_urls' => false,
                    'is_running' => true,
                ],
                '_samples' => [],
            ],
            'source_filename' => $options['buckets_file'],
            'stored_path' => null,
            'user_id' => Auth::id(),
            'started_at' => now(),
        ]);

        RunMetalmasterProductImportJob::dispatch($run->id, $options, $write);

        $this->lastRunId = $run->id;
        $this->refreshLastSavedRun();

        Notification::make()
            ->title('Запуск поставлен в очередь')
            ->body(
                'Запуск #'.$run->id
                .' отправлен в очередь (режим: '.$mode.'). '
                .'Для живого прогресса должен работать queue worker: php artisan queue:work'
            )
            ->success()
            ->send();
    }

    /**
     * @return array{
     *     buckets_file: string,
     *     bucket: string,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int
     * }
     */
    private function buildOptions(bool $write): array
    {
        return [
            'buckets_file' => trim((string) ($this->data['buckets_file'] ?? storage_path('app/parser/metalmaster-buckets.json'))),
            'bucket' => trim((string) ($this->data['bucket'] ?? '')),
            'limit' => max(0, (int) ($this->data['limit'] ?? 0)),
            'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) ($this->data['delay_ms'] ?? 250)),
            'write' => $write,
            'publish' => (bool) ($this->data['publish'] ?? false),
            'download_images' => (bool) ($this->data['download_images'] ?? true),
            'skip_existing' => (bool) ($this->data['skip_existing'] ?? false),
            'show_samples' => max(0, (int) ($this->data['show_samples'] ?? 3)),
        ];
    }

    public function refreshLastSavedRun(): void
    {
        $runQuery = ImportRun::query()
            ->where('type', 'metalmaster_products');

        if ($this->lastRunId !== null) {
            $runQuery->whereKey($this->lastRunId);
        }

        $run = $runQuery->latest('id')->first();

        if (! $run) {
            $run = ImportRun::query()
                ->where('type', 'metalmaster_products')
                ->latest('id')
                ->first();
        }

        if (! $run) {
            $this->lastSavedRun = null;
            $this->lastSavedIssues = [];

            return;
        }

        $totals = is_array($run->totals) ? $run->totals : [];
        $meta = data_get($totals, '_meta');

        if (! is_array($meta)) {
            $meta = [];
        }

        $columns = is_array($run->columns) ? $run->columns : [];

        $processed = (int) ($totals['scanned'] ?? 0);
        $foundUrls = (int) ($meta['found_urls'] ?? 0);
        $progressPercent = $foundUrls > 0
            ? max(0, min(100, (int) floor(($processed / $foundUrls) * 100)))
            : 0;

        $this->lastSavedRun = [
            'id' => $run->id,
            'status' => $run->status,
            'mode' => (string) ($meta['mode'] ?? 'unknown'),
            'is_running' => (bool) ($meta['is_running'] ?? ($run->status === 'pending')),
            'no_urls' => (bool) ($meta['no_urls'] ?? false),
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'progress_percent' => $progressPercent,
            'created' => (int) ($totals['create'] ?? 0),
            'updated' => (int) ($totals['update'] ?? 0),
            'skipped' => (int) ($totals['same'] ?? 0),
            'errors' => (int) ($totals['error'] ?? 0),
            'images_downloaded' => (int) ($meta['images_downloaded'] ?? 0),
            'image_download_failed' => (int) ($meta['image_download_failed'] ?? 0),
            'derivatives_queued' => (int) ($meta['derivatives_queued'] ?? 0),
            'samples_count' => count(is_array($totals['_samples'] ?? null) ? $totals['_samples'] : []),
            'bucket' => (string) ($columns['bucket'] ?? ''),
            'buckets_file' => (string) ($columns['buckets_file'] ?? ''),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i'),
        ];

        $this->lastSavedIssues = $run->issues()
            ->latest('id')
            ->limit(5)
            ->pluck('message')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     bucket: string,
     *     buckets_file: string,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int
     * }
     */
    private function defaultData(): array
    {
        return [
            'bucket' => '',
            'buckets_file' => storage_path('app/parser/metalmaster-buckets.json'),
            'limit' => 0,
            'timeout' => 25,
            'delay_ms' => 250,
            'publish' => false,
            'download_images' => true,
            'skip_existing' => false,
            'show_samples' => 3,
        ];
    }
}
