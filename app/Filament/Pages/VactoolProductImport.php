<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunVactoolProductImportJob;
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

class VactoolProductImport extends Page implements HasForms
{
    use InteractsWithForms;

    private const DEFAULT_SITEMAP = 'https://vactool.ru/sitemap.xml';

    private const DEFAULT_MATCH = '/catalog/product-';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Импорт/Экспорт';

    protected static ?string $navigationLabel = 'Импорт Vactool';

    protected static ?string $title = 'Импорт товаров из Vactool';

    protected string $view = 'filament.pages.vactool-product-import';

    /** @var array{
     *     limit: int,
     *     delay_ms: int,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int
     * }|null
     */
    public ?array $data = [
        'limit' => 0,
        'delay_ms' => 250,
        'publish' => false,
        'download_images' => true,
        'skip_existing' => false,
        'show_samples' => 3,
    ];

    /** @var array<string, int|string|null>|null */
    public ?array $lastSavedRun = null;

    /** @var array<int, string> */
    public array $lastSavedIssues = [];

    public ?int $lastRunId = null;

    public function mount(): void
    {
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
                Section::make('Параметры парсинга Vactool')
                    ->description('Источник фиксирован: https://vactool.ru/sitemap.xml; фильтр URL: /catalog/product-.')
                    ->schema([
                        TextInput::make('limit')
                            ->label('Лимит URL (0 = все)')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
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
                            ->hintIcon(Heroicon::InformationCircle, 'Если товар найден по ключу name + brand, он не будет обновлен.'),
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
            'type' => 'vactool_products',
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
            'source_filename' => $options['sitemap'],
            'stored_path' => null,
            'user_id' => Auth::id(),
            'started_at' => now(),
        ]);

        RunVactoolProductImportJob::dispatch($run->id, $options, $write);

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
     *     sitemap: string,
     *     match: string,
     *     limit: int,
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
            'sitemap' => self::DEFAULT_SITEMAP,
            'match' => self::DEFAULT_MATCH,
            'limit' => max(0, (int) ($this->data['limit'] ?? 0)),
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
            ->where('type', 'vactool_products');

        if ($this->lastRunId !== null) {
            $runQuery->whereKey($this->lastRunId);
        }

        $run = $runQuery->latest('id')->first();

        if (! $run) {
            $run = ImportRun::query()
                ->where('type', 'vactool_products')
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
}
