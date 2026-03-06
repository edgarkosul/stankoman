<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunMetalmasterProductImportJob;
use App\Jobs\RunVactoolProductImportJob;
use App\Models\ImportRun;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Suppliers\Metalmaster\MetalmasterSupplierProfile;
use App\Support\CatalogImport\Suppliers\Vactool\VactoolSupplierProfile;
use BackedEnum;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use UnitEnum;

class CatalogSupplierImport extends Page implements HasForms
{
    use InteractsWithForms;

    private const DISPLAY_TIMEZONE = 'Europe/Moscow';

    private const DEFAULT_VACTOOL_SOURCE = 'https://vactool.ru/sitemap.xml';

    protected string $view = 'filament.pages.catalog-supplier-import';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Импорт поставщиков';

    protected static ?string $title = 'Единый импорт поставщиков';

    /** @var array{
     *     supplier: string,
     *     profile: string,
     *     source: string,
     *     bucket: string,
     *     match: string,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     show_samples: int,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     mode: string,
     *     finalize_missing: bool,
     *     create_missing: bool,
     *     update_existing: bool,
     *     error_threshold_count: int|null,
     *     error_threshold_percent: float|null
     * }|null
     */
    public ?array $data = [
        'supplier' => 'vactool',
        'profile' => 'vactool_html',
        'source' => self::DEFAULT_VACTOOL_SOURCE,
        'bucket' => '',
        'match' => '/catalog/product-',
        'limit' => 0,
        'timeout' => 25,
        'delay_ms' => 250,
        'show_samples' => 3,
        'publish' => false,
        'download_images' => true,
        'skip_existing' => false,
        'mode' => 'partial_import',
        'finalize_missing' => false,
        'create_missing' => true,
        'update_existing' => true,
        'error_threshold_count' => null,
        'error_threshold_percent' => null,
    ];

    /** @var array<string, int|string|bool|null>|null */
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
                Section::make('Источник и профиль')
                    ->schema([
                        Select::make('supplier')
                            ->label('Поставщик')
                            ->options([
                                'vactool' => 'Vactool',
                                'metalmaster' => 'Metalmaster',
                            ])
                            ->default('vactool')
                            ->live()
                            ->native(false),
                        Select::make('profile')
                            ->label('Профиль')
                            ->options(fn (): array => $this->profileOptions((string) ($this->data['supplier'] ?? 'vactool')))
                            ->native(false),
                        TextInput::make('source')
                            ->label('Источник (URL или путь к файлу)')
                            ->helperText('Для Vactool: sitemap URL. Для Metalmaster: buckets JSON.')
                            ->required(),
                        TextInput::make('bucket')
                            ->label('Bucket (только для Metalmaster)')
                            ->placeholder('Например: promyshlennye'),
                        TextInput::make('match')
                            ->label('URL match (только для Vactool)')
                            ->placeholder('/catalog/product-'),
                    ]),
                Section::make('Параметры run')
                    ->schema([
                        TextInput::make('limit')
                            ->label('Лимит записей (0 = все)')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('timeout')
                            ->label('Таймаут запроса, сек (Metalmaster)')
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
                        Select::make('mode')
                            ->label('Режим синхронизации')
                            ->options([
                                'partial_import' => 'partial_import',
                                'full_sync_authoritative' => 'full_sync_authoritative',
                            ])
                            ->default('partial_import')
                            ->native(false),
                        Toggle::make('publish')
                            ->label('Публиковать импортированные товары'),
                        Toggle::make('download_images')
                            ->label('Скачивать изображения')
                            ->default(true),
                        Toggle::make('skip_existing')
                            ->label('Пропускать уже существующие товары (prefilter)'),
                        Toggle::make('finalize_missing')
                            ->label('Finalize missing (только full_sync)')
                            ->default(false),
                        Toggle::make('create_missing')
                            ->label('Создавать новые товары')
                            ->default(true),
                        Toggle::make('update_existing')
                            ->label('Обновлять существующие товары')
                            ->default(true),
                        TextInput::make('error_threshold_count')
                            ->label('Порог ошибок (count)')
                            ->numeric()
                            ->integer()
                            ->minValue(1),
                        TextInput::make('error_threshold_percent')
                            ->label('Порог ошибок (%)')
                            ->numeric()
                            ->minValue(0),
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
                            FormAction::make('stop_import')
                                ->label('Остановить текущий запуск')
                                ->color('warning')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => $this->hasActiveRun())
                                ->action('stopActiveImport'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function updatedDataSupplier(mixed $value): void
    {
        $supplier = is_string($value) ? $value : 'vactool';
        $this->data['profile'] = $this->defaultProfileForSupplier($supplier);
        $this->data['source'] = $this->defaultSourceForSupplier($supplier);
        $this->data['match'] = $supplier === 'vactool' ? '/catalog/product-' : '';
        $this->data['bucket'] = $supplier === 'metalmaster' ? (string) ($this->data['bucket'] ?? '') : '';
        $this->refreshLastSavedRun();
    }

    public function doDryRun(): void
    {
        $this->dispatchImport(false);
    }

    public function doImport(): void
    {
        $this->dispatchImport(true);
    }

    public function stopActiveImport(): void
    {
        $run = $this->resolveActiveRun();
        $runs = app(ImportRunOrchestrator::class);

        if (! $run) {
            Notification::make()
                ->title('Активный запуск не найден')
                ->body('Нет запуска со статусом "В ожидании" или "Выполняется".')
                ->warning()
                ->send();

            $this->refreshLastSavedRun();

            return;
        }

        $runs->markCancelled($run, $runs->resolveMode($run));
        $run->issues()->create([
            'row_index' => null,
            'code' => 'cancelled_by_user',
            'severity' => 'warning',
            'message' => 'Импорт остановлен пользователем из панели.',
            'row_snapshot' => [
                'user_id' => Auth::id(),
            ],
        ]);

        $this->lastRunId = $run->id;
        $this->refreshLastSavedRun();

        Notification::make()
            ->title('Запуск остановлен')
            ->body("Запуск #{$run->id} помечен как остановленный.")
            ->success()
            ->send();
    }

    private function dispatchImport(bool $write): void
    {
        if ($this->hasActiveRun()) {
            Notification::make()
                ->title('Запуск уже выполняется')
                ->body('Дождитесь завершения текущего запуска или остановите его.')
                ->warning()
                ->send();

            return;
        }

        $supplier = (string) ($this->data['supplier'] ?? 'vactool');
        $options = $this->buildOptions($write);
        $mode = $write ? 'write' : 'dry-run';
        $runType = $this->runType($supplier);

        $runs = app(ImportRunOrchestrator::class);
        $run = $runs->start(
            type: $runType,
            columns: $options,
            mode: $mode,
            sourceFilename: (string) ($options['sitemap'] ?? $options['buckets_file'] ?? $options['source'] ?? null),
            userId: Auth::id(),
            meta: [
                'supplier' => $supplier,
                'profile' => (string) ($options['profile'] ?? ''),
            ],
        );

        if ($supplier === 'vactool') {
            RunVactoolProductImportJob::dispatch($run->id, $options, $write);
        } else {
            RunMetalmasterProductImportJob::dispatch($run->id, $options, $write);
        }

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
     * @return array<string, mixed>
     */
    private function buildOptions(bool $write): array
    {
        $supplier = (string) ($this->data['supplier'] ?? 'vactool');
        $mode = (string) ($this->data['mode'] ?? 'partial_import');

        if (! in_array($mode, ['partial_import', 'full_sync_authoritative'], true)) {
            $mode = 'partial_import';
        }

        $commonOptions = [
            'write' => $write,
            'limit' => max(0, (int) ($this->data['limit'] ?? 0)),
            'delay_ms' => max(0, (int) ($this->data['delay_ms'] ?? 250)),
            'publish' => (bool) ($this->data['publish'] ?? false),
            'download_images' => (bool) ($this->data['download_images'] ?? true),
            'skip_existing' => (bool) ($this->data['skip_existing'] ?? false),
            'show_samples' => max(0, (int) ($this->data['show_samples'] ?? 3)),
            'mode' => $mode,
            'finalize_missing' => (bool) ($this->data['finalize_missing'] ?? ($mode === 'full_sync_authoritative')),
            'create_missing' => (bool) ($this->data['create_missing'] ?? true),
            'update_existing' => (bool) ($this->data['update_existing'] ?? true),
            'error_threshold_count' => $this->normalizeNullableInt($this->data['error_threshold_count'] ?? null),
            'error_threshold_percent' => $this->normalizeNullableFloat($this->data['error_threshold_percent'] ?? null),
            'profile' => (string) ($this->data['profile'] ?? $this->defaultProfileForSupplier($supplier)),
        ];

        if ($supplier === 'vactool') {
            $sitemap = trim((string) ($this->data['source'] ?? ''));

            return array_merge($commonOptions, [
                'sitemap' => $sitemap !== '' ? $sitemap : self::DEFAULT_VACTOOL_SOURCE,
                'match' => (string) ($this->data['match'] ?? '/catalog/product-'),
            ]);
        }

        if ($supplier === 'metalmaster') {
            $bucketsFile = trim((string) ($this->data['source'] ?? ''));

            return array_merge($commonOptions, [
                'buckets_file' => $bucketsFile !== '' ? $bucketsFile : storage_path('app/parser/metalmaster-buckets.json'),
                'bucket' => trim((string) ($this->data['bucket'] ?? '')),
                'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            ]);
        }

        $sitemap = trim((string) ($this->data['source'] ?? ''));

        return array_merge($commonOptions, [
            'sitemap' => $sitemap !== '' ? $sitemap : self::DEFAULT_VACTOOL_SOURCE,
            'match' => (string) ($this->data['match'] ?? '/catalog/product-'),
        ]);
    }

    public function refreshLastSavedRun(): void
    {
        $supplier = (string) ($this->data['supplier'] ?? 'vactool');
        $runType = $this->runType($supplier);
        $runQuery = ImportRun::query()->where('type', $runType);

        if ($this->lastRunId !== null) {
            $runQuery->whereKey($this->lastRunId);
        }

        $run = $runQuery->latest('id')->first();

        if (! $run) {
            $run = ImportRun::query()
                ->where('type', $runType)
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
            'is_running' => (bool) ($meta['is_running'] ?? in_array($run->status, ['pending', 'running'], true)),
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
            'finished_at' => $run->finished_at?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i'),
        ];

        $this->lastSavedIssues = $run->issues()
            ->latest('id')
            ->limit(5)
            ->pluck('message')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    private function hasActiveRun(): bool
    {
        return $this->resolveActiveRun() !== null;
    }

    private function resolveActiveRun(): ?ImportRun
    {
        if (! DatabaseSchema::hasTable('import_runs')) {
            return null;
        }

        $runType = $this->runType((string) ($this->data['supplier'] ?? 'vactool'));
        $runQuery = ImportRun::query()
            ->where('type', $runType)
            ->whereIn('status', ['pending', 'running']);

        if ($this->lastRunId !== null) {
            $lastRun = (clone $runQuery)->whereKey($this->lastRunId)->first();

            if ($lastRun) {
                return $lastRun;
            }
        }

        return $runQuery->latest('id')->first();
    }

    /**
     * @return array<string, string>
     */
    private function profileOptions(string $supplier): array
    {
        if ($supplier === 'metalmaster') {
            $profile = app(MetalmasterSupplierProfile::class)->profileKey();

            return [$profile => $profile];
        }

        $profile = app(VactoolSupplierProfile::class)->profileKey();

        return [$profile => $profile];
    }

    private function defaultProfileForSupplier(string $supplier): string
    {
        if ($supplier === 'metalmaster') {
            return app(MetalmasterSupplierProfile::class)->profileKey();
        }

        return app(VactoolSupplierProfile::class)->profileKey();
    }

    private function defaultSourceForSupplier(string $supplier): string
    {
        return $supplier === 'metalmaster'
            ? storage_path('app/parser/metalmaster-buckets.json')
            : self::DEFAULT_VACTOOL_SOURCE;
    }

    private function runType(string $supplier): string
    {
        return match ($supplier) {
            'metalmaster' => 'metalmaster_products',
            default => 'vactool_products',
        };
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
