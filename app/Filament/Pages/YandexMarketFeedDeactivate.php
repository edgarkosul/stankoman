<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunYandexMarketFeedDeactivationJob;
use App\Models\Category;
use App\Models\ImportFeedSource;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use App\Support\CatalogImport\Yml\YandexMarketFeedSourceHistoryService;
use BackedEnum;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;
use UnitEnum;

class YandexMarketFeedDeactivate extends Page implements HasForms
{
    use InteractsWithForms;

    private const DISPLAY_TIMEZONE = 'Europe/Moscow';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-power';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Деактивация Yandex Feed';

    protected static ?string $title = 'Деактивация товаров по Yandex Market Feed';

    protected string $view = 'filament.pages.yandex-market-feed-deactivate';

    /** @var array{
     *     supplier_id: int|null,
     *     source_mode: string,
     *     source_url: string,
     *     source_upload: TemporaryUploadedFile|array<int|string, TemporaryUploadedFile|string>|string|null,
     *     source_history_id: int|null,
     *     site_category_id: int|null,
     *     timeout: int,
     *     show_samples: int
     * }|null
     */
    public ?array $data = null;

    /** @var array<string, int|string|bool|null>|null */
    public ?array $lastSavedRun = null;

    /** @var array<int, string> */
    public array $lastSavedIssues = [];

    /** @var array<int, array<string, string|int>> */
    public array $lastSavedSamples = [];

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
            FormAction::make('import')
                ->label('Обычный импорт')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('gray')
                ->url(YandexMarketFeedImport::getUrl()),
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
                Section::make('Поставщик и область деактивации')
                    ->description('Система использует выбранный XML/YML feed как есть. Ответственность за корректность поставщика, категории сайта и самого фида целиком на администраторе.')
                    ->schema([
                        Select::make('supplier_id')
                            ->label('Поставщик')
                            ->placeholder('Выберите или создайте поставщика')
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->options(fn (): array => $this->supplierOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->supplierOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->supplierOptionLabel($value))
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Название поставщика')
                                    ->required()
                                    ->maxLength(160),
                            ])
                            ->createOptionUsing(fn (array $data): int => $this->createSupplierFromData($data)),
                        Select::make('site_category_id')
                            ->label('Категория сайта для деактивации')
                            ->placeholder('Выберите категорию сайта')
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->options(fn (): array => $this->siteCategoryOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->siteCategoryOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->siteCategoryOptionLabel($value))
                            ->hintIcon(
                                Heroicon::InformationCircle,
                                'Деактивация затронет выбранную категорию сайта и все её дочерние категории.',
                            ),
                    ]),
                Section::make('Источник Yandex Market Feed')
                    ->schema([
                        Select::make('source_mode')
                            ->label('Источник фида')
                            ->options([
                                'url' => 'URL',
                                'upload' => 'Загрузить файл',
                                'history' => 'Из истории успешных',
                            ])
                            ->native(false)
                            ->default('url')
                            ->live(),
                        TextInput::make('source_url')
                            ->label('URL фида')
                            ->placeholder('https://example.test/yandex-market.xml')
                            ->visible(fn (Get $get): bool => (string) $get('source_mode') === 'url')
                            ->required(fn (Get $get): bool => (string) $get('source_mode') === 'url'),
                        FileUpload::make('source_upload')
                            ->label('Файл фида (XML/YML)')
                            ->acceptedFileTypes([
                                'application/xml',
                                'text/xml',
                                'application/octet-stream',
                                'text/plain',
                            ])
                            ->preserveFilenames()
                            ->disk('local')
                            ->directory(YandexMarketFeedSourceHistoryService::temporaryUploadDirectory())
                            ->visibility('private')
                            ->visible(fn (Get $get): bool => (string) $get('source_mode') === 'upload')
                            ->required(fn (Get $get): bool => (string) $get('source_mode') === 'upload'),
                        Select::make('source_history_id')
                            ->label('Успешный источник из истории')
                            ->placeholder('Выберите ранее валидированный источник')
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get): bool => (string) $get('source_mode') === 'history')
                            ->required(fn (Get $get): bool => (string) $get('source_mode') === 'history')
                            ->options(fn (): array => app(YandexMarketFeedSourceHistoryService::class)->historyOptions(limit: 100))
                            ->getSearchResultsUsing(
                                fn (string $search): array => app(YandexMarketFeedSourceHistoryService::class)->historyOptions(search: $search, limit: 100),
                            )
                            ->getOptionLabelUsing(fn ($value): ?string => app(YandexMarketFeedSourceHistoryService::class)->historyOptionLabel($value)),
                    ]),
                Section::make('Параметры dry-run')
                    ->schema([
                        TextInput::make('timeout')
                            ->label('Таймаут запроса, сек')
                            ->numeric()
                            ->integer()
                            ->minValue(1),
                        TextInput::make('show_samples')
                            ->label('Кандидаты в превью dry-run')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                    ]),
                Section::make('Запуск')
                    ->schema([
                        Actions::make([
                            FormAction::make('dry_run')
                                ->label('Проверить кандидатов (dry-run)')
                                ->color('success')
                                ->action('doDryRun'),
                            FormAction::make('apply')
                                ->label('Деактивировать найденные товары')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('doApply'),
                            FormAction::make('stop')
                                ->label('Остановить текущий запуск')
                                ->color('warning')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => $this->hasActiveRun())
                                ->action('stopActiveRun'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function doDryRun(): void
    {
        $this->dispatchRun(false);
    }

    public function doApply(): void
    {
        $this->dispatchRun(true);
    }

    public function stopActiveRun(): void
    {
        $run = $this->resolveActiveRun();
        $runs = app(ImportRunOrchestrator::class);

        if (! $run) {
            Notification::make()
                ->title('Активный запуск не найден')
                ->body('Для деактивации по Yandex Feed нет запуска со статусом "В ожидании" или "Выполняется".')
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
            'message' => 'Запуск деактивации остановлен пользователем из панели.',
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

    public function refreshLastSavedRun(): void
    {
        if (! DatabaseSchema::hasTable('import_runs')) {
            $this->lastSavedRun = null;
            $this->lastSavedIssues = [];
            $this->lastSavedSamples = [];

            return;
        }

        $runQuery = ImportRun::query()->where('type', 'yandex_market_feed_deactivation');

        if ($this->lastRunId !== null) {
            $runQuery->whereKey($this->lastRunId);
        }

        $run = $runQuery->latest('id')->first();

        if (! $run) {
            $run = ImportRun::query()
                ->where('type', 'yandex_market_feed_deactivation')
                ->latest('id')
                ->first();
        }

        if (! $run) {
            $this->lastSavedRun = null;
            $this->lastSavedIssues = [];
            $this->lastSavedSamples = [];

            return;
        }

        $totals = is_array($run->totals) ? $run->totals : [];
        $meta = data_get($totals, '_meta');

        if (! is_array($meta)) {
            $meta = [];
        }

        $columns = is_array($run->columns) ? $run->columns : [];

        $this->lastSavedRun = [
            'id' => $run->id,
            'status' => $run->status,
            'mode' => (string) ($meta['mode'] ?? 'unknown'),
            'is_running' => (bool) ($meta['is_running'] ?? in_array($run->status, ['pending', 'running'], true)),
            'no_urls' => (bool) ($meta['no_urls'] ?? false),
            'found_urls' => (int) ($meta['found_urls'] ?? 0),
            'processed' => (int) ($totals['scanned'] ?? 0),
            'errors' => (int) ($totals['error'] ?? 0),
            'candidates' => (int) ($meta['candidates'] ?? 0),
            'deactivated' => (int) ($meta['deactivated'] ?? 0),
            'supplier_label' => trim((string) ($columns['supplier_name'] ?? $meta['supplier_name'] ?? '')),
            'site_category_label' => trim((string) ($columns['site_category_name'] ?? $meta['site_category_name'] ?? '')),
            'source' => trim((string) ($columns['source_label'] ?? $columns['source'] ?? '')),
            'finished_at' => $run->finished_at?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i'),
        ];

        $this->lastSavedIssues = $run->issues()
            ->latest('id')
            ->limit(5)
            ->pluck('message')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        $this->lastSavedSamples = array_values(array_filter(
            is_array($totals['_samples'] ?? null) ? $totals['_samples'] : [],
            'is_array',
        ));
    }

    private function dispatchRun(bool $write): void
    {
        if ($this->hasActiveRun()) {
            Notification::make()
                ->title('Запуск уже выполняется')
                ->body('Дождитесь завершения текущего dry-run/apply по деактивации или остановите его.')
                ->warning()
                ->send();

            $this->refreshLastSavedRun();

            return;
        }

        $supplierId = $this->normalizeNullableInt($this->data['supplier_id'] ?? null);
        $siteCategoryId = $this->normalizeNullableInt($this->data['site_category_id'] ?? null);

        if ($supplierId === null || ! Supplier::query()->whereKey($supplierId)->exists()) {
            Notification::make()
                ->title('Выберите поставщика')
                ->warning()
                ->send();

            return;
        }

        if ($siteCategoryId === null || ! Category::query()->whereKey($siteCategoryId)->exists()) {
            Notification::make()
                ->title('Выберите категорию сайта')
                ->warning()
                ->send();

            return;
        }

        try {
            $resolvedSource = $this->resolveSelectedSource();
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Не указан источник фида')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        try {
            app(YandexMarketFeedImportService::class)->listCategoryNodes([
                'source' => $resolvedSource['source'],
                'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            ]);
            $resolvedSource = $this->rememberSuccessfulSource($resolvedSource);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Фид не прошел предварительную проверку')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $options = $this->buildOptions($write, $resolvedSource);
        $mode = $write ? 'write' : 'dry-run';
        $runs = app(ImportRunOrchestrator::class);
        $run = $runs->start(
            type: 'yandex_market_feed_deactivation',
            columns: $options,
            mode: $mode,
            sourceFilename: $options['source_label'] ?: $options['source'],
            userId: Auth::id(),
        );

        $sourceId = $this->normalizeNullableInt($options['source_id'] ?? null);

        if ($sourceId !== null) {
            app(YandexMarketFeedSourceHistoryService::class)->markUsedById($sourceId, $run->id);
        }

        RunYandexMarketFeedDeactivationJob::dispatch($run->id, $options, $write)->afterCommit();

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
     *     source: string,
     *     source_type: string,
     *     source_id: int|null,
     *     source_label: string,
     *     supplier_id: int|null,
     *     supplier_name: string,
     *     site_category_id: int|null,
     *     site_category_name: string,
     *     timeout: int,
     *     show_samples: int,
     *     write: bool
     * }
     */
    private function buildOptions(bool $write, array $resolvedSource): array
    {
        $supplierId = $this->normalizeNullableInt($this->data['supplier_id'] ?? null);
        $siteCategoryId = $this->normalizeNullableInt($this->data['site_category_id'] ?? null);

        return [
            'source' => (string) $resolvedSource['source'],
            'source_type' => (string) ($resolvedSource['source_type'] ?? YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL),
            'source_id' => $this->normalizeNullableInt($resolvedSource['source_id'] ?? null),
            'source_label' => (string) ($resolvedSource['source_label'] ?? $resolvedSource['source']),
            'supplier_id' => $supplierId,
            'supplier_name' => trim((string) Supplier::query()->whereKey($supplierId)->value('name')),
            'site_category_id' => $siteCategoryId,
            'site_category_name' => trim((string) Category::query()->whereKey($siteCategoryId)->value('name')),
            'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            'show_samples' => max(0, (int) ($this->data['show_samples'] ?? 20)),
            'write' => $write,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultData(): array
    {
        return [
            'supplier_id' => null,
            'source_mode' => 'url',
            'source_url' => '',
            'source_upload' => null,
            'source_history_id' => null,
            'site_category_id' => null,
            'timeout' => 25,
            'show_samples' => 20,
        ];
    }

    /**
     * @return array{
     *     source: string,
     *     source_type: string,
     *     source_id: int|null,
     *     source_label: string,
     *     source_url: string|null,
     *     stored_path: string|null,
     *     original_filename: string|null,
     *     source_key: string
     * }
     */
    private function resolveSelectedSource(): array
    {
        $mode = (string) ($this->data['source_mode'] ?? 'url');

        if ($mode === 'history') {
            $historyId = $this->normalizeNullableInt($this->data['source_history_id'] ?? null);

            if ($historyId === null) {
                throw new RuntimeException('Выберите источник из истории.');
            }

            $resolved = app(YandexMarketFeedSourceHistoryService::class)->resolveFromHistoryId($historyId);

            if (! is_array($resolved)) {
                throw new RuntimeException('Источник из истории недоступен. Выберите другой или загрузите новый.');
            }

            $sourceType = (string) ($resolved['source_type'] ?? '');
            $storedPath = is_string($resolved['stored_path'] ?? null) ? trim((string) $resolved['stored_path']) : null;
            $sourceUrl = is_string($resolved['source_url'] ?? null) ? trim((string) $resolved['source_url']) : null;

            return [
                'source' => (string) $resolved['source'],
                'source_type' => $sourceType,
                'source_id' => $this->normalizeNullableInt($resolved['source_id'] ?? null),
                'source_label' => (string) ($resolved['source_label'] ?? ''),
                'source_url' => $sourceUrl,
                'stored_path' => $storedPath,
                'original_filename' => $sourceType === YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD
                    ? (($storedPath !== null && $storedPath !== '') ? basename($storedPath) : null)
                    : null,
                'source_key' => 'history|'.$historyId,
            ];
        }

        if ($mode === 'upload') {
            $storedPath = $this->resolveStoredFeedUploadPath($this->data['source_upload'] ?? null);

            if ($storedPath === null) {
                throw new RuntimeException('Загрузите XML/YML файл фида перед запуском.');
            }

            if (! Storage::disk('local')->exists($storedPath)) {
                throw new RuntimeException('Загруженный файл не найден. Повторите загрузку.');
            }

            return [
                'source' => Storage::disk('local')->path($storedPath),
                'source_type' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD,
                'source_id' => null,
                'source_label' => basename($storedPath),
                'source_url' => null,
                'stored_path' => $storedPath,
                'original_filename' => basename($storedPath),
                'source_key' => 'upload|'.$storedPath,
            ];
        }

        $sourceUrl = trim((string) ($this->data['source_url'] ?? ''));

        if ($sourceUrl === '') {
            throw new RuntimeException('Укажите URL фида перед запуском.');
        }

        return [
            'source' => $sourceUrl,
            'source_type' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL,
            'source_id' => null,
            'source_label' => $sourceUrl,
            'source_url' => $sourceUrl,
            'stored_path' => null,
            'original_filename' => null,
            'source_key' => 'url|'.$sourceUrl,
        ];
    }

    /**
     * @param  array{
     *     source: string,
     *     source_type: string,
     *     source_id: int|null,
     *     source_label: string,
     *     source_url: string|null,
     *     stored_path: string|null,
     *     original_filename: string|null,
     *     source_key: string
     * }  $resolvedSource
     * @return array{
     *     source: string,
     *     source_type: string,
     *     source_id: int|null,
     *     source_label: string,
     *     source_url: string|null,
     *     stored_path: string|null,
     *     original_filename: string|null,
     *     source_key: string
     * }
     */
    private function rememberSuccessfulSource(array $resolvedSource): array
    {
        $historyService = app(YandexMarketFeedSourceHistoryService::class);
        $userId = Auth::id();
        $record = null;

        if (($resolvedSource['source_type'] ?? null) === YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD) {
            $storedPath = trim((string) ($resolvedSource['stored_path'] ?? ''));

            if ($storedPath === '') {
                return $resolvedSource;
            }

            $record = $historyService->rememberValidUploadedPath(
                storedPath: $storedPath,
                originalFilename: is_string($resolvedSource['original_filename'] ?? null)
                    ? trim((string) $resolvedSource['original_filename'])
                    : null,
                userId: $userId,
            );
        } else {
            $sourceUrl = trim((string) ($resolvedSource['source_url'] ?? $resolvedSource['source'] ?? ''));

            if ($sourceUrl === '') {
                return $resolvedSource;
            }

            $record = $historyService->rememberValidUrl(
                url: $sourceUrl,
                userId: $userId,
            );
        }

        if (! $record instanceof ImportFeedSource) {
            return $resolvedSource;
        }

        return $this->applyRememberedSourceToState($resolvedSource, $record);
    }

    /**
     * @param  array{
     *     source: string,
     *     source_type: string,
     *     source_id: int|null,
     *     source_label: string,
     *     source_url: string|null,
     *     stored_path: string|null,
     *     original_filename: string|null,
     *     source_key: string
     * }  $resolvedSource
     * @return array{
     *     source: string,
     *     source_type: string,
     *     source_id: int|null,
     *     source_label: string,
     *     source_url: string|null,
     *     stored_path: string|null,
     *     original_filename: string|null,
     *     source_key: string
     * }
     */
    private function applyRememberedSourceToState(array $resolvedSource, ImportFeedSource $record): array
    {
        $sourceType = (string) $record->source_type;

        if ($sourceType === YandexMarketFeedSourceHistoryService::SOURCE_TYPE_UPLOAD) {
            $storedPath = trim((string) ($record->stored_path ?? ''));

            if ($storedPath !== '') {
                if (is_array($this->data)) {
                    $this->data['source_upload'] = [$storedPath];
                }

                $resolvedSource['source'] = Storage::disk('local')->path($storedPath);
                $resolvedSource['source_type'] = $sourceType;
                $resolvedSource['source_id'] = (int) $record->id;
                $resolvedSource['source_label'] = trim((string) ($record->original_filename ?: basename($storedPath)));
                $resolvedSource['source_url'] = null;
                $resolvedSource['stored_path'] = $storedPath;
                $resolvedSource['original_filename'] = trim((string) ($record->original_filename ?: basename($storedPath)));
                $resolvedSource['source_key'] = 'upload|'.$storedPath;

                return $resolvedSource;
            }

            return $resolvedSource;
        }

        $sourceUrl = trim((string) ($record->source_url ?? ''));

        if ($sourceUrl !== '' && is_array($this->data)) {
            $this->data['source_url'] = $sourceUrl;
        }

        if ($sourceUrl === '') {
            return $resolvedSource;
        }

        $resolvedSource['source'] = $sourceUrl;
        $resolvedSource['source_type'] = YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL;
        $resolvedSource['source_id'] = (int) $record->id;
        $resolvedSource['source_label'] = $sourceUrl;
        $resolvedSource['source_url'] = $sourceUrl;
        $resolvedSource['stored_path'] = null;
        $resolvedSource['original_filename'] = null;
        $resolvedSource['source_key'] = 'url|'.$sourceUrl;

        return $resolvedSource;
    }

    private function resolveStoredFeedUploadPath(mixed $value): ?string
    {
        if ($value instanceof TemporaryUploadedFile) {
            $storedPath = $value->store(path: YandexMarketFeedSourceHistoryService::temporaryUploadDirectory(), options: 'local');

            return is_string($storedPath) && $storedPath !== '' ? $storedPath : null;
        }

        if (is_array($value)) {
            $first = reset($value);

            if ($first instanceof TemporaryUploadedFile) {
                $storedPath = $first->store(path: YandexMarketFeedSourceHistoryService::temporaryUploadDirectory(), options: 'local');

                return is_string($storedPath) && $storedPath !== '' ? $storedPath : null;
            }

            if (is_string($first) && trim($first) !== '') {
                return ltrim(trim($first), '/');
            }

            return null;
        }

        if (is_string($value) && trim($value) !== '') {
            return ltrim(trim($value), '/');
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function supplierOptions(?string $search = null, int $limit = 100): array
    {
        if (! DatabaseSchema::hasTable('suppliers')) {
            return [];
        }

        $query = Supplier::query()
            ->where('is_active', true)
            ->orderBy('name');

        $needle = trim((string) $search);

        if ($needle !== '') {
            $query->where('name', 'like', '%'.$needle.'%');
        }

        return $query
            ->limit($limit)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name])
            ->all();
    }

    private function supplierOptionLabel(mixed $value): ?string
    {
        $supplierId = $this->normalizeNullableInt($value);

        if ($supplierId === null || ! DatabaseSchema::hasTable('suppliers')) {
            return null;
        }

        $name = Supplier::query()->whereKey($supplierId)->value('name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param  array{name?: mixed}  $data
     */
    private function createSupplierFromData(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Название поставщика не может быть пустым.');
        }

        $supplier = Supplier::query()->firstOrCreate(
            ['name' => $name],
            ['is_active' => true],
        );

        return (int) $supplier->getKey();
    }

    /**
     * @return array<string, string>
     */
    private function siteCategoryOptions(?string $search = null, int $limit = 100): array
    {
        if (! DatabaseSchema::hasTable('categories')) {
            return [];
        }

        $query = Category::query()->orderBy('name');
        $needle = trim((string) $search);

        if ($needle !== '') {
            $query->where(function (Builder $builder) use ($needle): void {
                $builder
                    ->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('id', $needle);
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Category $category): array => [
                (string) $category->getKey() => '['.$category->getKey().'] '.$category->name,
            ])
            ->all();
    }

    private function siteCategoryOptionLabel(mixed $value): ?string
    {
        $categoryId = $this->normalizeNullableInt($value);

        if ($categoryId === null || ! DatabaseSchema::hasTable('categories')) {
            return null;
        }

        $category = Category::query()->find($categoryId);

        if (! $category instanceof Category) {
            return null;
        }

        return '['.$category->getKey().'] '.$category->name;
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

        $runQuery = ImportRun::query()
            ->where('type', 'yandex_market_feed_deactivation')
            ->whereIn('status', ['pending', 'running']);

        if ($this->lastRunId !== null) {
            $lastRun = (clone $runQuery)->whereKey($this->lastRunId)->first();

            if ($lastRun) {
                return $lastRun;
            }
        }

        return $runQuery->latest('id')->first();
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }
}
