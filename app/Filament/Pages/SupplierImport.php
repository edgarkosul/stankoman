<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Drivers\Contracts\SupplierImportDriver;
use App\Support\CatalogImport\Drivers\DriverAvailability;
use App\Support\CatalogImport\Drivers\ImportDriverRegistry;
use App\Support\CatalogImport\Drivers\MetaltecXmlDriver;
use App\Support\CatalogImport\Drivers\YandexMarketFeedDriver;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\Metalmaster\MetalmasterBucketCatalog;
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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use UnitEnum;

class SupplierImport extends Page implements HasForms
{
    use InteractsWithForms;

    private const DISPLAY_TIMEZONE = 'Europe/Moscow';

    private const PAGE_STATE_SESSION_KEY = 'filament.supplier-import.page-state';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Импорт поставщиков';

    protected static ?string $title = 'Единый импорт поставщиков';

    protected string $view = 'filament.pages.supplier-import';

    public ?array $data = null;

    public ?array $lastSavedRun = null;

    public array $lastSavedIssues = [];

    public array $lastSavedSamples = [];

    public ?int $lastRunId = null;

    /** @var array<int, string> */
    public array $yandexParsedCategories = [];

    /** @var array<int, array{id: int, name: string, parent_id: int|null, depth: int, is_leaf: bool, tree_name: string}> */
    public array $yandexParsedCategoryTree = [];

    /** @var array<int, true> */
    public array $yandexLeafCategoryIds = [];

    public ?string $yandexCategoriesLoadedAt = null;

    public ?string $yandexCategoriesLoadedSource = null;

    public ?string $yandexCategoriesLoadedSourceKey = null;

    /** @var array<int, string> */
    public array $metaltecParsedCategories = [];

    /** @var array<int, array{id: int, name: string, parent_id: int|null, depth: int, is_leaf: bool, tree_name: string}> */
    public array $metaltecParsedCategoryTree = [];

    /** @var array<int, true> */
    public array $metaltecLeafCategoryIds = [];

    public ?string $metaltecCategoriesLoadedAt = null;

    public ?string $metaltecCategoriesLoadedSource = null;

    public ?string $metaltecCategoriesLoadedSourceKey = null;

    private bool $isSyncScenarioInternalUpdate = false;

    public function mount(): void
    {
        $this->data = is_array($this->data) ? $this->data : $this->defaultData();
        $this->restorePageState();

        if ($this->currentSource() instanceof SupplierImportSource) {
            $this->loadSelectedSource();

            return;
        }

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
            FormAction::make('create_supplier')
                ->label('Создать поставщика')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->form([
                    TextInput::make('name')
                        ->label('Название поставщика')
                        ->required()
                        ->maxLength(160),
                ])
                ->modalHeading('Новый поставщик')
                ->modalSubmitActionLabel('Создать')
                ->action(function (array $data): void {
                    $this->createSupplier($data);
                }),
            FormAction::make('delete_supplier')
                ->label('Удалить поставщика')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->currentSupplier() instanceof Supplier)
                ->requiresConfirmation()
                ->modalHeading('Удалить поставщика')
                ->modalDescription('Удаление доступно только если у поставщика нет товарных привязок и истории запусков.')
                ->modalSubmitActionLabel('Удалить')
                ->action(function (): void {
                    $this->deleteSelectedSupplier();
                }),
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
                Section::make('Поставщик')
                    ->schema([
                        Select::make('supplier_id')
                            ->hiddenLabel()
                            ->placeholder('Выберите или создайте поставщика')
                            ->helperText(fn (): ?string => $this->supplierHelperText())
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->live()
                            ->options(fn (): array => $this->supplierOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->supplierOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->supplierOptionLabel($value))
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Название поставщика')
                                    ->required()
                                    ->maxLength(160),
                            ])
                            ->createOptionAction(fn (FormAction $action): FormAction => $action
                                ->label('Создать поставщика')
                                ->modalHeading('Новый поставщик')
                                ->modalSubmitActionLabel('Создать'))
                            ->createOptionUsing(fn (array $data): int => $this->createSupplierFromData($data))
                            ->afterStateUpdated(function (): void {
                                $this->handleSupplierChanged();
                            }),
                    ]),
                Section::make('Вариант импорта')
                    ->description('Вариант импорта включает в себя настройки драйвера и его параметров для конкретного поставщика. Вы можете сохранять варианты импорта для повторного использования или создавать новый вариант при каждом запуске.')
                    ->schema([
                        Select::make('supplier_import_source_id')
                            ->label('Сохраненный вариант')
                            ->placeholder('Новый вариант импорта')
                            ->searchable()
                            ->native(false)
                            ->live()
                            ->disabled(fn (Get $get): bool => $this->normalizeNullableInt($get('supplier_id')) === null)
                            ->options(fn (): array => $this->supplierImportSourceOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->supplierImportSourceOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->supplierImportSourceOptionLabel($value))
                            ->afterStateUpdated(function (): void {
                                $this->loadSelectedSource();
                            }),
                        Actions::make([
                            FormAction::make('new_source')
                                ->label('Новый вариант')
                                ->color('gray')
                                ->action('startNewSource'),
                            FormAction::make('save_source')
                                ->label('Сохранить вариант')
                                ->color('primary')
                                ->action('saveSource'),
                        ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('source_name')
                                    ->label('Название варианта')
                                    ->required()
                                    ->maxLength(160),
                                Select::make('driver_key')
                                    ->label('Драйвер')
                                    ->required()
                                    ->helperText(fn (): string => $this->driverHelperText())
                                    ->native(false)
                                    ->live()
                                    ->options(fn (): array => $this->availableDriverOptions())
                                    ->afterStateUpdated(function ($state): void {
                                        $this->handleDriverChanged((string) $state);
                                    }),
                                Toggle::make('source_is_active')
                                    ->label('Активен'),
                            ]),
                        Grid::make(2)
                            ->schema(fn (): array => $this->currentDriver()->settingsSchema()),
                    ]),
                Section::make('Запуск импорта')
                    ->schema([
                        Grid::make(2)
                            ->schema(array_merge(
                                $this->commonImportRuntimeSchema(),
                                $this->currentDriver()->importRuntimeSchema(),
                            )),
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
                                ->label('Остановить импорт')
                                ->color('warning')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => $this->hasActiveImportRun())
                                ->action('stopImportRun'),
                        ]),
                    ]),
                Section::make('Деактивация')
                    ->description('Деактивация работает только для выбранного поставщика и категории сайта. Проверка выполняется по feed текущего варианта импорта. Если товара нет в feed, он будет деактивирован. Перед нажатием Apply обязательно запустите dry-run.')
                    ->visible(fn (): bool => $this->currentDriver()->supportsDeactivation())
                    ->schema([
                        Grid::make(2)
                            ->schema($this->currentDriver()->deactivationRuntimeSchema()),
                        Actions::make([
                            FormAction::make('deactivation_dry_run')
                                ->label('Dry-run деактивации')
                                ->color('success')
                                ->action('doDeactivationDryRun'),
                            FormAction::make('deactivation_apply')
                                ->label('Применить деактивацию')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->action('doDeactivationApply'),
                            FormAction::make('stop_deactivation')
                                ->label('Остановить деактивацию')
                                ->color('warning')
                                ->requiresConfirmation()
                                ->visible(fn (): bool => $this->hasActiveDeactivationRun())
                                ->action('stopDeactivationRun'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function startNewSource(): void
    {
        $this->data = array_merge($this->data ?? [], $this->freshSourceState());
        $this->resetFeedCategoriesIfSourceChanged();
        $this->form->fill($this->data);
        $this->refreshLastSavedRun();
    }

    public function saveSource(): void
    {
        try {
            $source = $this->persistSource();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            Notification::make()
                ->title('Не удалось сохранить вариант')
                ->body('Проверьте обязательные поля и повторите попытку.')
                ->warning()
                ->send();

            return;
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Не удалось сохранить вариант')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Вариант сохранен')
            ->body("Вариант #{$source->id} сохранен и готов к запуску.")
            ->success()
            ->send();
    }

    public function doDryRun(): void
    {
        $this->dispatchImport(false);
    }

    public function doImport(): void
    {
        $this->dispatchImport(true);
    }

    public function doDeactivationDryRun(): void
    {
        $this->dispatchDeactivation(false);
    }

    public function doDeactivationApply(): void
    {
        $this->dispatchDeactivation(true);
    }

    public function stopImportRun(): void
    {
        $this->stopActiveRun(
            $this->currentDriver()->importRunType(),
            'Импорт остановлен пользователем из единого экрана.',
        );
    }

    public function stopDeactivationRun(): void
    {
        $runType = $this->currentDriver()->deactivationRunType();

        if (! is_string($runType) || $runType === '') {
            return;
        }

        $this->stopActiveRun(
            $runType,
            'Запуск деактивации остановлен пользователем из единого экрана.',
        );
    }

    public function syncMetalmasterBuckets(): void
    {
        if ($this->currentDriver()->key() !== 'metalmaster_html') {
            Notification::make()
                ->title('Синхронизация недоступна')
                ->body('Buckets можно синхронизировать только для драйвера Metalmaster HTML.')
                ->warning()
                ->send();

            return;
        }

        try {
            $exitCode = Artisan::call('parser:sitemap-buckets', [
                '--no-interaction' => true,
            ]);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Не удалось синхронизировать buckets')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());

            Notification::make()
                ->title('Команда завершилась с ошибкой')
                ->body($output !== '' ? $output : 'parser:sitemap-buckets завершилась с кодом '.$exitCode.'.')
                ->danger()
                ->send();

            return;
        }

        $bucketCatalog = app(MetalmasterBucketCatalog::class);
        $selectedBucket = trim((string) data_get($this->data, 'runtime.scope', ''));

        if ($selectedBucket !== '' && ! $bucketCatalog->hasBucket($selectedBucket)) {
            data_set($this->data, 'runtime.scope', '');
        }

        $this->form->fill($this->data);

        $output = trim(Artisan::output());

        Notification::make()
            ->title('Buckets синхронизированы')
            ->body($output !== '' ? $output : 'Команда parser:sitemap-buckets выполнена успешно.')
            ->success()
            ->send();
    }

    public function loadYandexFeedCategories(): void
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof YandexMarketFeedDriver) {
            Notification::make()
                ->title('Загрузка недоступна')
                ->body('Категории feed можно загружать только для драйвера Yandex Market Feed.')
                ->warning()
                ->send();

            return;
        }

        try {
            $loadedCategories = $driver->loadFeedCategories($this->currentSourceSettings());
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Не удалось загрузить категории')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->yandexParsedCategories = $loadedCategories['categories'];
        $this->yandexParsedCategoryTree = $loadedCategories['category_tree'];
        $this->yandexLeafCategoryIds = $loadedCategories['leaf_category_ids'];
        $this->yandexCategoriesLoadedAt = now()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s');
        $this->yandexCategoriesLoadedSource = $loadedCategories['source_label'];
        $this->yandexCategoriesLoadedSourceKey = $loadedCategories['source_key'];

        $selectedCategoryId = $this->normalizeNullableInt(data_get($this->data, 'runtime.category_id'));

        if ($selectedCategoryId !== null && ! isset($this->yandexParsedCategoryTree[$selectedCategoryId])) {
            data_set($this->data, 'runtime.category_id', null);
        }

        $this->form->fill($this->data);
        $this->rememberPageState();

        Notification::make()
            ->title('Категории загружены')
            ->body(
                'Найдено категорий: '.count($this->yandexParsedCategories)
                .'. Листовых: '.count($this->yandexLeafCategoryIds).'.'
            )
            ->success()
            ->send();
    }

    public function loadMetaltecFeedCategories(): void
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof MetaltecXmlDriver) {
            Notification::make()
                ->title('Загрузка недоступна')
                ->body('Категории feed можно загружать только для драйвера Metaltec XML.')
                ->warning()
                ->send();

            return;
        }

        try {
            $loadedCategories = $driver->loadFeedCategories($this->currentSourceSettings());
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Не удалось загрузить категории')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->metaltecParsedCategories = $loadedCategories['categories'];
        $this->metaltecParsedCategoryTree = $loadedCategories['category_tree'];
        $this->metaltecLeafCategoryIds = $loadedCategories['leaf_category_ids'];
        $this->metaltecCategoriesLoadedAt = now()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s');
        $this->metaltecCategoriesLoadedSource = $loadedCategories['source_label'];
        $this->metaltecCategoriesLoadedSourceKey = $loadedCategories['source_key'];

        $selectedCategoryId = $this->normalizeNullableInt(data_get($this->data, 'runtime.category_id'));

        if ($selectedCategoryId !== null && ! isset($this->metaltecParsedCategoryTree[$selectedCategoryId])) {
            data_set($this->data, 'runtime.category_id', null);
            data_set($this->data, 'runtime.category_name', null);
        }

        $this->form->fill($this->data);
        $this->rememberPageState();

        Notification::make()
            ->title('Категории загружены')
            ->body(
                'Найдено категорий: '.count($this->metaltecParsedCategories)
                .'. Разделы определены по полю <Раздел>.'
            )
            ->success()
            ->send();
    }

    public function refreshLastSavedRun(): void
    {
        if (! DatabaseSchema::hasTable('import_runs')) {
            $this->lastSavedRun = null;
            $this->lastSavedIssues = [];
            $this->lastSavedSamples = [];
            $this->rememberPageState();

            return;
        }

        $runQuery = ImportRun::query()->with(['supplier', 'supplierImportSource']);

        if ($this->lastRunId !== null) {
            $runQuery->whereKey($this->lastRunId);
        } else {
            $sourceId = $this->normalizeNullableInt(data_get($this->data, 'supplier_import_source_id'));
            $supplierId = $this->normalizeNullableInt(data_get($this->data, 'supplier_id'));

            if ($sourceId !== null) {
                $runQuery->where('supplier_import_source_id', $sourceId);
            } elseif ($supplierId !== null) {
                $runQuery->where('supplier_id', $supplierId);
            } else {
                $this->lastSavedRun = null;
                $this->lastSavedIssues = [];
                $this->lastSavedSamples = [];
                $this->rememberPageState();

                return;
            }
        }

        $run = $runQuery->latest('id')->first();

        if (! $run instanceof ImportRun) {
            $this->lastSavedRun = null;
            $this->lastSavedIssues = [];
            $this->lastSavedSamples = [];
            $this->rememberPageState();

            return;
        }

        $totals = is_array($run->totals) ? $run->totals : [];
        $meta = is_array(data_get($totals, '_meta')) ? data_get($totals, '_meta') : [];
        $columns = is_array($run->columns) ? $run->columns : [];
        $processed = (int) ($totals['scanned'] ?? 0);
        $foundUrls = (int) ($meta['found_urls'] ?? 0);
        $progressPercent = $foundUrls > 0
            ? max(0, min(100, (int) floor(($processed / $foundUrls) * 100)))
            : 0;

        $this->lastSavedRun = [
            'id' => $run->id,
            'type' => (string) $run->type,
            'type_label' => $this->typeLabel((string) $run->type),
            'status' => (string) $run->status,
            'mode' => (string) ($meta['mode'] ?? 'unknown'),
            'is_running' => (bool) ($meta['is_running'] ?? in_array((string) $run->status, ['pending', 'running'], true)),
            'no_urls' => (bool) ($meta['no_urls'] ?? false),
            'supplier_label' => trim((string) ($run->supplier?->name ?? $columns['supplier_name'] ?? $meta['supplier_name'] ?? '')),
            'import_source_label' => trim((string) ($run->supplierImportSource?->name ?? $columns['supplier_import_source_name'] ?? '')),
            'source' => trim((string) ($columns['source_label'] ?? $columns['source'] ?? '')),
            'scope' => trim((string) ($columns['bucket'] ?? $columns['scope'] ?? '')),
            'feed_category_id' => $this->normalizeNullableInt($columns['category_id'] ?? null),
            'feed_category_label' => trim((string) ($columns['category_name'] ?? '')),
            'site_category_label' => trim((string) ($columns['site_category_name'] ?? $meta['site_category_name'] ?? '')),
            'found_urls' => $foundUrls,
            'processed' => $processed,
            'progress_percent' => $progressPercent,
            'created' => (int) ($totals['create'] ?? 0),
            'updated' => (int) ($totals['update'] ?? 0),
            'skipped' => (int) ($totals['same'] ?? 0),
            'errors' => (int) ($totals['error'] ?? 0),
            'candidates' => (int) ($meta['candidates'] ?? 0),
            'deactivated' => (int) ($meta['deactivated'] ?? 0),
            'images_downloaded' => (int) ($meta['images_downloaded'] ?? 0),
            'derivatives_queued' => (int) ($meta['derivatives_queued'] ?? 0),
            'finished_at' => $run->finished_at?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i'),
        ];

        $this->lastSavedIssues = $run->issues()
            ->latest('id')
            ->limit(5)
            ->pluck('message')
            ->filter(fn ($message): bool => is_string($message) && trim($message) !== '')
            ->values()
            ->all();

        $this->lastSavedSamples = array_values(array_filter(
            is_array($totals['_samples'] ?? null) ? $totals['_samples'] : [],
            'is_array',
        ));

        $this->rememberPageState();
    }

    private function handleSupplierChanged(): void
    {
        $this->lastRunId = null;
        $this->clearYandexFeedCategories(resetRuntimeCategory: true);
        $this->clearMetaltecFeedCategories(resetRuntimeCategory: true);

        if ($this->currentSupplier() === null) {
            $this->data = array_merge($this->data ?? [], [
                'supplier_id' => null,
            ], $this->freshSourceState());
            $this->form->fill($this->data);
            $this->refreshLastSavedRun();

            return;
        }

        $sourceId = SupplierImportSource::query()
            ->where('supplier_id', $this->currentSupplier()?->id)
            ->orderBy('name')
            ->value('id');

        if (is_numeric($sourceId)) {
            $this->data['supplier_import_source_id'] = (int) $sourceId;
            $this->loadSelectedSource();

            return;
        }

        $this->startNewSource();
    }

    private function loadSelectedSource(): void
    {
        $source = $this->currentSource();

        if (! $source instanceof SupplierImportSource) {
            $this->data = array_merge($this->data ?? [], $this->freshSourceState());
            $this->form->fill($this->data);
            $this->refreshLastSavedRun();

            return;
        }

        $driver = $this->drivers()->get($source->driver_key)
            ?? $this->defaultDriverForCurrentSupplier($source->driver_key);
        $settings = array_merge(
            $driver->defaultSettings(),
            is_array($source->settings) ? $source->settings : [],
        );

        $this->data = array_merge($this->data ?? [], [
            'supplier_import_source_id' => $source->id,
            'source_name' => $source->name,
            'driver_key' => $driver->key(),
            'source_is_active' => (bool) $source->is_active,
            'source_settings' => $settings,
        ]);

        $this->resetFeedCategoriesIfSourceChanged();
        $this->form->fill($this->data);
        $this->refreshLastSavedRun();
    }

    private function handleDriverChanged(string $driverKey): void
    {
        $driver = $this->resolveSelectableDriver($driverKey);
        $sourceSettings = is_array(data_get($this->data, 'source_settings')) ? data_get($this->data, 'source_settings') : [];
        $sourceName = trim((string) data_get($this->data, 'source_name', ''));

        $this->data['driver_key'] = $driver->key();
        $this->data['source_settings'] = array_merge($driver->defaultSettings(), $sourceSettings);

        if ($sourceName === '') {
            $this->data['source_name'] = $driver->defaultSourceName();
        }

        if ($driver->key() === 'yandex_market_feed') {
            $this->applySyncScenario('standard');
        }

        $this->resetFeedCategoriesIfSourceChanged();
        $this->form->fill($this->data);
    }

    private function dispatchImport(bool $write): void
    {
        try {
            $source = $this->persistSource();
            $driver = $this->drivers()->get($source->driver_key)
                ?? $this->defaultDriverForCurrentSupplier($source->driver_key);
            $this->resetFeedCategoriesIfSourceChanged();
            $runtime = $this->normalizedRuntime();

            if ($write && ! $runtime['create_missing'] && ! $runtime['update_existing']) {
                throw new RuntimeException('Одновременно отключены создание новых и обновление существующих товаров.');
            }

            if ($this->hasActiveRunForType($driver->importRunType(), $source)) {
                throw new RuntimeException('Для выбранного варианта уже выполняется импорт. Дождитесь завершения или остановите его.');
            }

            $options = $driver->buildImportOptions($source, $runtime);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            Notification::make()
                ->title('Импорт не запущен')
                ->body('Проверьте обязательные поля варианта импорта.')
                ->warning()
                ->send();

            return;
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Импорт не запущен')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        $mode = $write ? 'write' : 'dry-run';
        $run = app(ImportRunOrchestrator::class)->start(
            type: $driver->importRunType(),
            columns: array_merge($options, [
                'driver_key' => $driver->key(),
                'supplier_import_source_name' => $source->name,
                'write' => $write,
            ]),
            mode: $mode,
            sourceFilename: $options['source_label'] ?? $driver->sourceLabel((array) $source->settings),
            userId: Auth::id(),
            meta: [
                'driver_key' => $driver->key(),
                'supplier_name' => $source->supplier?->name,
                'supplier_import_source_name' => $source->name,
            ],
            supplierId: $source->supplier_id,
            supplierImportSourceId: $source->id,
        );

        $driver->dispatchImport($run, $options, $write);
        $source->forceFill(['last_used_at' => now()])->save();

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

    private function dispatchDeactivation(bool $write): void
    {
        try {
            $source = $this->persistSource();
            $driver = $this->drivers()->get($source->driver_key)
                ?? $this->defaultDriverForCurrentSupplier($source->driver_key);
            $deactivation = $this->normalizedDeactivation();

            if (! $driver->supportsDeactivation()) {
                throw new RuntimeException('Выбранный драйвер не поддерживает деактивацию.');
            }

            if ($this->normalizeNullableInt($deactivation['site_category_id'] ?? null) === null) {
                throw new RuntimeException('Выберите категорию сайта для деактивации.');
            }

            if ($this->hasActiveRunForType((string) $driver->deactivationRunType(), $source)) {
                throw new RuntimeException('Для выбранного варианта уже выполняется деактивация. Дождитесь завершения или остановите ее.');
            }

            if ($write && ! $this->hasRecentDeactivationDryRun($source, (string) $driver->deactivationRunType())) {
                throw new RuntimeException('Сначала выполните dry-run деактивации для текущего варианта и категории сайта.');
            }

            $options = $driver->buildDeactivationOptions($source, $deactivation);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            Notification::make()
                ->title('Деактивация не запущена')
                ->body('Проверьте обязательные поля варианта импорта и блока деактивации.')
                ->warning()
                ->send();

            return;
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Деактивация не запущена')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        $mode = $write ? 'write' : 'dry-run';
        $run = app(ImportRunOrchestrator::class)->start(
            type: (string) $driver->deactivationRunType(),
            columns: array_merge($options, [
                'driver_key' => $driver->key(),
                'supplier_import_source_name' => $source->name,
                'write' => $write,
            ]),
            mode: $mode,
            sourceFilename: $options['source_label'] ?? $driver->sourceLabel((array) $source->settings),
            userId: Auth::id(),
            meta: [
                'driver_key' => $driver->key(),
                'supplier_name' => $source->supplier?->name,
                'supplier_import_source_name' => $source->name,
            ],
            supplierId: $source->supplier_id,
            supplierImportSourceId: $source->id,
        );

        $driver->dispatchDeactivation($run, $options, $write);
        $source->forceFill(['last_used_at' => now()])->save();

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

    private function stopActiveRun(string $type, string $message): void
    {
        $source = $this->currentSource();

        if (! $source instanceof SupplierImportSource) {
            Notification::make()
                ->title('Активный запуск не найден')
                ->warning()
                ->send();

            return;
        }

        $run = ImportRun::query()
            ->where('type', $type)
            ->where('supplier_import_source_id', $source->id)
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        if (! $run instanceof ImportRun) {
            Notification::make()
                ->title('Активный запуск не найден')
                ->warning()
                ->send();

            return;
        }

        $runs = app(ImportRunOrchestrator::class);
        $runs->markCancelled($run, $runs->resolveMode($run));

        $run->issues()->create([
            'row_index' => null,
            'code' => 'cancelled_by_user',
            'severity' => 'warning',
            'message' => $message,
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

    private function currentSupplier(): ?Supplier
    {
        $supplierId = $this->normalizeNullableInt(data_get($this->data, 'supplier_id'));

        if ($supplierId === null || ! DatabaseSchema::hasTable('suppliers')) {
            return null;
        }

        return Supplier::query()->find($supplierId);
    }

    public function resetYandexFeedCategoriesIfSourceChanged(): void
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof YandexMarketFeedDriver) {
            $this->clearYandexFeedCategories(resetRuntimeCategory: false);

            return;
        }

        $sourceKey = $driver->sourceKey($this->currentSourceSettings());

        if ($sourceKey === $this->yandexCategoriesLoadedSourceKey) {
            return;
        }

        $this->clearYandexFeedCategories(resetRuntimeCategory: true);
    }

    public function resetMetaltecFeedCategoriesIfSourceChanged(): void
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof MetaltecXmlDriver) {
            $this->clearMetaltecFeedCategories(resetRuntimeCategory: false);

            return;
        }

        $sourceKey = $driver->sourceKey($this->currentSourceSettings());

        if ($sourceKey === $this->metaltecCategoriesLoadedSourceKey) {
            return;
        }

        $this->clearMetaltecFeedCategories(resetRuntimeCategory: true);
    }

    /**
     * @return array<string, string>
     */
    public function metaltecFeedCategoryOptions(?string $search = null, int $limit = 100): array
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof MetaltecXmlDriver || ! $this->hasLoadedMetaltecFeedCategories()) {
            return [];
        }

        return $driver->categoryOptions($this->metaltecParsedCategoryTree, $search, $limit);
    }

    public function metaltecFeedCategoryOptionLabel(mixed $value): ?string
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof MetaltecXmlDriver || ! $this->hasLoadedMetaltecFeedCategories()) {
            return null;
        }

        return $driver->categoryOptionLabel($this->metaltecParsedCategoryTree, $this->metaltecParsedCategories, $value);
    }

    public function metaltecFeedCategoryHelperText(): string
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof MetaltecXmlDriver) {
            return 'Оставьте пустым для импорта всего feed.';
        }

        $sourceSettings = $this->currentSourceSettings();
        $sourceKey = $driver->sourceKey($sourceSettings);
        $sourceIsSelected = trim((string) ($sourceSettings['source_url'] ?? '')) !== '';

        if (! $sourceIsSelected) {
            return 'Сначала укажите URL фида, затем загрузите категории из поля <Раздел>.';
        }

        if ($this->metaltecParsedCategoryTree === []) {
            return 'Нажмите "Загрузить категории <Раздел>", затем выберите раздел. Оставьте пустым для импорта всего feed.';
        }

        if ($sourceKey !== $this->metaltecCategoriesLoadedSourceKey) {
            return 'Источник изменился. Загрузите категории заново перед выбором раздела.';
        }

        $sourceLabel = $this->metaltecCategoriesLoadedSource ?? 'текущий источник';
        $loadedAt = $this->metaltecCategoriesLoadedAt ?? 'только что';

        return 'Загружено категорий: '.count($this->metaltecParsedCategories)
            .'. Источник: '.$sourceLabel
            .'. Обновлено: '.$loadedAt.'.';
    }

    /**
     * @return array<string, string>
     */
    public function yandexFeedCategoryOptions(?string $search = null, int $limit = 100): array
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof YandexMarketFeedDriver || ! $this->hasLoadedYandexFeedCategories()) {
            return [];
        }

        return $driver->categoryOptions($this->yandexParsedCategoryTree, $search, $limit);
    }

    public function yandexFeedCategoryOptionLabel(mixed $value): ?string
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof YandexMarketFeedDriver || ! $this->hasLoadedYandexFeedCategories()) {
            return null;
        }

        return $driver->categoryOptionLabel($this->yandexParsedCategoryTree, $this->yandexParsedCategories, $value);
    }

    public function yandexFeedCategoryHelperText(): string
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof YandexMarketFeedDriver) {
            return 'Оставьте пустым для импорта всего feed.';
        }

        $sourceSettings = $this->currentSourceSettings();
        $sourceKey = $driver->sourceKey($sourceSettings);
        $sourceIsSelected = (($sourceSettings['source_mode'] ?? null) === 'history')
            ? $this->normalizeNullableInt($sourceSettings['source_history_id'] ?? null) !== null
            : trim((string) ($sourceSettings['source_url'] ?? '')) !== '';

        if (! $sourceIsSelected) {
            return 'Сначала укажите URL фида или выберите источник из истории, затем загрузите категории <category>.';
        }

        if ($this->yandexParsedCategoryTree === []) {
            return 'Нажмите "Загрузить категории <category>", затем выберите категорию. Оставьте пустым для импорта всего feed.';
        }

        if ($sourceKey !== $this->yandexCategoriesLoadedSourceKey) {
            return 'Источник изменился. Загрузите категории заново перед выбором категории feed.';
        }

        $sourceLabel = $this->yandexCategoriesLoadedSource ?? 'текущий источник';
        $loadedAt = $this->yandexCategoriesLoadedAt ?? 'только что';

        return 'Загружено категорий: '.count($this->yandexParsedCategories)
            .'. Листовых: '.count($this->yandexLeafCategoryIds)
            .'. Источник: '.$sourceLabel
            .'. Обновлено: '.$loadedAt.'.';
    }

    private function restorePageState(): void
    {
        $state = session()->get(self::PAGE_STATE_SESSION_KEY);

        if (is_array($state)) {
            $this->data['supplier_id'] = $this->normalizeNullableInt($state['supplier_id'] ?? null);
            $this->data['supplier_import_source_id'] = $this->normalizeNullableInt($state['supplier_import_source_id'] ?? null);
            $this->lastRunId = $this->normalizeNullableInt($state['last_run_id'] ?? null);
        }

        if (
            $this->normalizeNullableInt(data_get($this->data, 'supplier_import_source_id')) !== null
            && ! $this->currentSource() instanceof SupplierImportSource
        ) {
            $this->data['supplier_import_source_id'] = null;
        }

        if ($this->currentSource() instanceof SupplierImportSource) {
            return;
        }

        if ($this->lastRunId !== null) {
            $this->restoreContextFromRunId($this->lastRunId);
        }

        if ($this->currentSource() instanceof SupplierImportSource) {
            return;
        }

        $this->restoreContextFromActiveRun();
    }

    private function currentSource(): ?SupplierImportSource
    {
        $sourceId = $this->normalizeNullableInt(data_get($this->data, 'supplier_import_source_id'));
        $supplierId = $this->normalizeNullableInt(data_get($this->data, 'supplier_id'));

        if ($sourceId === null || $supplierId === null || ! DatabaseSchema::hasTable('supplier_import_sources')) {
            return null;
        }

        return SupplierImportSource::query()
            ->whereKey($sourceId)
            ->where('supplier_id', $supplierId)
            ->first();
    }

    private function clearYandexFeedCategories(bool $resetRuntimeCategory = true): void
    {
        $this->yandexParsedCategories = [];
        $this->yandexParsedCategoryTree = [];
        $this->yandexLeafCategoryIds = [];
        $this->yandexCategoriesLoadedAt = null;
        $this->yandexCategoriesLoadedSource = null;
        $this->yandexCategoriesLoadedSourceKey = null;

        if ($resetRuntimeCategory && is_array($this->data)) {
            data_set($this->data, 'runtime.category_id', null);
        }
    }

    private function clearMetaltecFeedCategories(bool $resetRuntimeCategory = true): void
    {
        $this->metaltecParsedCategories = [];
        $this->metaltecParsedCategoryTree = [];
        $this->metaltecLeafCategoryIds = [];
        $this->metaltecCategoriesLoadedAt = null;
        $this->metaltecCategoriesLoadedSource = null;
        $this->metaltecCategoriesLoadedSourceKey = null;

        if ($resetRuntimeCategory && is_array($this->data)) {
            data_set($this->data, 'runtime.category_id', null);
            data_set($this->data, 'runtime.category_name', null);
        }
    }

    private function hasLoadedYandexFeedCategories(): bool
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof YandexMarketFeedDriver) {
            return false;
        }

        return $this->yandexParsedCategoryTree !== []
            && $driver->sourceKey($this->currentSourceSettings()) === $this->yandexCategoriesLoadedSourceKey;
    }

    private function hasLoadedMetaltecFeedCategories(): bool
    {
        $driver = $this->currentDriver();

        if (! $driver instanceof MetaltecXmlDriver) {
            return false;
        }

        return $this->metaltecParsedCategoryTree !== []
            && $driver->sourceKey($this->currentSourceSettings()) === $this->metaltecCategoriesLoadedSourceKey;
    }

    private function restoreContextFromRunId(int $runId): void
    {
        if (! DatabaseSchema::hasTable('import_runs')) {
            return;
        }

        $run = ImportRun::query()->find($runId);

        if ($run instanceof ImportRun) {
            $this->restoreContextFromRun($run);
        }
    }

    private function restoreContextFromActiveRun(): void
    {
        if (! DatabaseSchema::hasTable('import_runs')) {
            return;
        }

        $run = ImportRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->whereNotNull('supplier_id')
            ->whereNotNull('supplier_import_source_id')
            ->latest('id')
            ->first();

        if ($run instanceof ImportRun) {
            $this->restoreContextFromRun($run);
        }
    }

    private function restoreContextFromRun(ImportRun $run): void
    {
        $supplierId = $this->normalizeNullableInt($run->supplier_id);
        $sourceId = $this->normalizeNullableInt($run->supplier_import_source_id);

        if (
            $supplierId === null
            || $sourceId === null
            || ! DatabaseSchema::hasTable('supplier_import_sources')
        ) {
            return;
        }

        $sourceExists = SupplierImportSource::query()
            ->whereKey($sourceId)
            ->where('supplier_id', $supplierId)
            ->exists();

        if (! $sourceExists) {
            return;
        }

        $this->data['supplier_id'] = $supplierId;
        $this->data['supplier_import_source_id'] = $sourceId;
        $this->lastRunId = $run->id;
    }

    private function rememberPageState(): void
    {
        session()->put(self::PAGE_STATE_SESSION_KEY, [
            'supplier_id' => $this->normalizeNullableInt(data_get($this->data, 'supplier_id')),
            'supplier_import_source_id' => $this->normalizeNullableInt(data_get($this->data, 'supplier_import_source_id')),
            'last_run_id' => $this->lastRunId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function freshSourceState(): array
    {
        $driver = $this->defaultDriverForCurrentSupplier();

        return [
            'supplier_import_source_id' => null,
            'source_name' => $driver->defaultSourceName(),
            'driver_key' => $driver->key(),
            'source_is_active' => true,
            'source_settings' => $driver->defaultSettings(),
            'runtime' => $this->defaultRuntime(),
            'deactivation' => $this->defaultDeactivation(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultData(): array
    {
        return array_merge([
            'supplier_id' => null,
        ], $this->freshSourceState());
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRuntime(): array
    {
        return [
            'limit' => 0,
            'show_samples' => 3,
            'publish' => false,
            'force_media_recheck' => false,
            'skip_existing' => false,
            'sync_scenario' => 'standard',
            'create_missing' => true,
            'update_existing' => true,
            'error_threshold_count' => null,
            'error_threshold_percent' => null,
            'scope' => '',
            'category_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultDeactivation(): array
    {
        return [
            'site_category_id' => null,
            'show_samples' => 20,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function commonImportRuntimeSchema(): array
    {
        return [
            TextInput::make('runtime.limit')
                ->label('Лимит записей (0 = все)')
                ->numeric()
                ->integer()
                ->minValue(0),
            TextInput::make('runtime.show_samples')
                ->label('Примеры строк в dry-run')
                ->numeric()
                ->integer()
                ->minValue(0),
            Select::make('runtime.sync_scenario')
                ->label('Сценарий импорта')
                ->options(fn (): array => $this->syncScenarioOptions())
                ->native(false)
                ->live()
                ->helperText(fn (Get $get): string => $this->syncScenarioSummary((string) $get('runtime.sync_scenario')))
                ->afterStateUpdated(function ($state): void {
                    $this->applySyncScenario((string) $state);
                }),
            Toggle::make('runtime.publish')
                ->label('Публиковать импортированные товары'),
            Toggle::make('runtime.force_media_recheck')
                ->label('Принудительно перепроверять медиа у донора'),
            Toggle::make('runtime.skip_existing')
                ->label('Пропускать существующие товары')
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->updateSyncScenarioFromFlags();
                }),
            Toggle::make('runtime.create_missing')
                ->label('Создавать новые товары')
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->updateSyncScenarioFromFlags();
                }),
            Toggle::make('runtime.update_existing')
                ->label('Обновлять существующие товары')
                ->disabled(fn (Get $get): bool => (bool) $get('runtime.skip_existing'))
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->updateSyncScenarioFromFlags();
                }),
            // TextInput::make('runtime.error_threshold_count')
            //     ->label('Порог ошибок (count)')
            //     ->numeric()
            //     ->integer()
            //     ->minValue(1),
            // TextInput::make('runtime.error_threshold_percent')
            //     ->label('Порог ошибок (%)')
            //     ->numeric()
            //     ->minValue(0),
        ];
    }

    private function currentDriver(): SupplierImportDriver
    {
        $driverKey = trim((string) data_get($this->data, 'driver_key', ''));
        $currentSourceDriverKey = $this->currentSource()?->driver_key;
        $availableDrivers = $this->drivers()->availableForSupplier(
            $this->currentSupplier(),
            $currentSourceDriverKey,
        );

        if ($driverKey !== '' && isset($availableDrivers[$driverKey])) {
            return $availableDrivers[$driverKey];
        }

        return $this->defaultDriverForCurrentSupplier($currentSourceDriverKey);
    }

    private function drivers(): ImportDriverRegistry
    {
        return app(ImportDriverRegistry::class);
    }

    public function createSupplier(array $data): void
    {
        try {
            $supplier = $this->createOrFindSupplier($data);
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Не удалось создать поставщика')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        $created = $supplier->wasRecentlyCreated;

        $this->data['supplier_id'] = (int) $supplier->getKey();
        $this->handleSupplierChanged();

        Notification::make()
            ->title($created ? 'Поставщик создан' : 'Поставщик выбран')
            ->body(
                $created
                    ? "Поставщик «{$supplier->name}» создан и выбран для текущего импорта."
                    : "Поставщик «{$supplier->name}» уже существовал и выбран для текущего импорта."
            )
            ->success()
            ->send();
    }

    public function deleteSelectedSupplier(): void
    {
        $supplier = $this->currentSupplier();

        if (! $supplier instanceof Supplier) {
            Notification::make()
                ->title('Поставщик не выбран')
                ->warning()
                ->send();

            return;
        }

        $blockers = $this->supplierDeletionBlockers($supplier);

        if ($blockers !== []) {
            Notification::make()
                ->title('Удаление заблокировано')
                ->body('Сначала удалите или перенесите зависимости: '.implode(', ', $blockers).'.')
                ->warning()
                ->send();

            return;
        }

        $supplierName = $supplier->name;
        $deletedSources = DatabaseSchema::hasTable('supplier_import_sources')
            ? $supplier->importSources()->count()
            : 0;

        $supplier->delete();

        $this->lastRunId = null;
        $this->data = array_merge($this->data ?? [], [
            'supplier_id' => null,
        ], $this->freshSourceState());
        $this->form->fill($this->data);
        $this->refreshLastSavedRun();

        Notification::make()
            ->title('Поставщик удален')
            ->body(
                $deletedSources > 0
                    ? "Поставщик «{$supplierName}» и его варианты импорта ({$deletedSources}) удалены."
                    : "Поставщик «{$supplierName}» удален."
            )
            ->success()
            ->send();
    }

    private function createSupplierFromData(array $data): int
    {
        return (int) $this->createOrFindSupplier($data)->getKey();
    }

    private function createOrFindSupplier(array $data): Supplier
    {
        if (! DatabaseSchema::hasTable('suppliers')) {
            throw new RuntimeException('Таблица suppliers еще не создана миграциями.');
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Название поставщика не может быть пустым.');
        }

        return Supplier::query()->firstOrCreate(
            ['name' => $name],
            ['is_active' => true],
        );
    }

    /**
     * @return array<string, string>
     */
    private function supplierOptions(?string $search = null, int $limit = 100): array
    {
        if (! DatabaseSchema::hasTable('suppliers')) {
            return [];
        }

        $query = Supplier::query()->orderBy('name');
        $needle = trim((string) $search);

        if ($needle !== '') {
            $query->where('name', 'like', "%{$needle}%");
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Supplier $supplier): array => [
                (string) $supplier->getKey() => $this->supplierDisplayLabel($supplier),
            ])
            ->all();
    }

    private function supplierOptionLabel(mixed $value): ?string
    {
        $supplierId = $this->normalizeNullableInt($value);

        if ($supplierId === null) {
            return null;
        }

        $supplier = Supplier::query()->find($supplierId);

        return $supplier instanceof Supplier
            ? $this->supplierDisplayLabel($supplier)
            : null;
    }

    private function supplierHelperText(): ?string
    {
        $supplier = $this->currentSupplier();

        if (! $supplier instanceof Supplier) {
            return null;
        }

        if (! $this->isLegacyTechnicalSupplier($supplier)) {
            return null;
        }

        return 'Legacy-технический supplier из старого Yandex entrypoint. Для новых импортов создавайте реального бизнес-поставщика.';
    }

    private function supplierDisplayLabel(Supplier $supplier): string
    {
        $label = trim((string) $supplier->name);

        if ($this->isLegacyTechnicalSupplier($supplier)) {
            return $label.' · legacy';
        }

        return $label;
    }

    private function isLegacyTechnicalSupplier(Supplier $supplier): bool
    {
        return trim((string) $supplier->slug) === 'yandex-market-feed';
    }

    /**
     * @return array<string, string>
     */
    private function supplierImportSourceOptions(?string $search = null, int $limit = 100): array
    {
        $supplierId = $this->normalizeNullableInt(data_get($this->data, 'supplier_id'));

        if ($supplierId === null || ! DatabaseSchema::hasTable('supplier_import_sources')) {
            return [];
        }

        $query = SupplierImportSource::query()
            ->where('supplier_id', $supplierId)
            ->orderBy('name');

        $needle = trim((string) $search);

        if ($needle !== '') {
            $query->where('name', 'like', "%{$needle}%");
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(function (SupplierImportSource $source): array {
                $driver = $this->drivers()->get($source->driver_key);
                $label = $source->name;

                if ($driver instanceof SupplierImportDriver) {
                    $label .= ' · '.$driver->label();
                }

                return [(string) $source->id => $label];
            })
            ->all();
    }

    private function supplierImportSourceOptionLabel(mixed $value): ?string
    {
        $sourceId = $this->normalizeNullableInt($value);

        if ($sourceId === null) {
            return null;
        }

        $source = SupplierImportSource::query()->find($sourceId);

        if (! $source instanceof SupplierImportSource) {
            return null;
        }

        $driver = $this->drivers()->get($source->driver_key);

        return $driver instanceof SupplierImportDriver
            ? $source->name.' · '.$driver->label()
            : $source->name;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedRuntime(): array
    {
        $runtime = is_array(data_get($this->data, 'runtime')) ? data_get($this->data, 'runtime') : [];
        $categoryId = $this->normalizeNullableInt($runtime['category_id'] ?? null);
        $categoryName = null;

        if ($this->currentDriver() instanceof YandexMarketFeedDriver && ! $this->hasLoadedYandexFeedCategories()) {
            $categoryId = null;
        }

        if ($this->currentDriver() instanceof MetaltecXmlDriver) {
            if (! $this->hasLoadedMetaltecFeedCategories()) {
                $categoryId = null;
            } elseif ($categoryId !== null) {
                $categoryName = $this->metaltecFeedCategoryOptionLabel($categoryId);
            }
        }

        return [
            'limit' => max(0, (int) ($runtime['limit'] ?? 0)),
            'show_samples' => max(0, (int) ($runtime['show_samples'] ?? 3)),
            'publish' => (bool) ($runtime['publish'] ?? false),
            'force_media_recheck' => (bool) ($runtime['force_media_recheck'] ?? false),
            'skip_existing' => (bool) ($runtime['skip_existing'] ?? false),
            'sync_scenario' => (string) ($runtime['sync_scenario'] ?? 'standard'),
            'create_missing' => (bool) ($runtime['create_missing'] ?? true),
            'update_existing' => (bool) ($runtime['update_existing'] ?? true),
            'error_threshold_count' => $this->normalizeNullableInt($runtime['error_threshold_count'] ?? null),
            'error_threshold_percent' => $this->normalizeNullableFloat($runtime['error_threshold_percent'] ?? null),
            'scope' => trim((string) ($runtime['scope'] ?? '')),
            'category_id' => $categoryId,
            'category_name' => $categoryName,
        ];
    }

    private function resetFeedCategoriesIfSourceChanged(): void
    {
        $this->resetYandexFeedCategoriesIfSourceChanged();
        $this->resetMetaltecFeedCategoriesIfSourceChanged();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedDeactivation(): array
    {
        $runtime = is_array(data_get($this->data, 'deactivation')) ? data_get($this->data, 'deactivation') : [];

        return [
            'site_category_id' => $this->normalizeNullableInt($runtime['site_category_id'] ?? null),
            'show_samples' => max(0, (int) ($runtime['show_samples'] ?? 20)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSourceSettings(): array
    {
        $settings = is_array(data_get($this->data, 'source_settings')) ? data_get($this->data, 'source_settings') : [];

        return $this->currentDriver()->normalizeSettings($settings);
    }

    /**
     * @return array<string, string>
     */
    private function availableDriverOptions(): array
    {
        return $this->drivers()->optionsForSupplier(
            $this->currentSupplier(),
            $this->currentSource()?->driver_key,
        );
    }

    private function driverHelperText(): string
    {
        $supplier = $this->currentSupplier();
        $availableDrivers = $this->drivers()->availableForSupplier(
            $supplier,
            $this->currentSource()?->driver_key,
        );

        $specializedCount = collect($availableDrivers)
            ->filter(fn (SupplierImportDriver $driver): bool => $driver->availability() === DriverAvailability::SupplierSpecific)
            ->count();

        if (! $supplier instanceof Supplier) {
            return 'До выбора поставщика доступны только универсальные драйверы.';
        }

        if ($specializedCount === 0) {
            return 'Для этого поставщика доступны только универсальные драйверы.';
        }

        return 'Yandex Market Feed доступен для любого поставщика. Специализированные драйверы под конкретного поставщика надо заказывать у разработчика.';
    }

    private function defaultDriverForCurrentSupplier(?string $includeKey = null): SupplierImportDriver
    {
        return $this->drivers()->defaultForSupplier(
            $this->currentSupplier(),
            $includeKey ?? $this->currentSource()?->driver_key,
        );
    }

    private function resolveSelectableDriver(?string $driverKey): SupplierImportDriver
    {
        $availableDrivers = $this->drivers()->availableForSupplier(
            $this->currentSupplier(),
            $this->currentSource()?->driver_key,
        );

        if (is_string($driverKey) && trim($driverKey) !== '' && isset($availableDrivers[$driverKey])) {
            return $availableDrivers[$driverKey];
        }

        return $this->defaultDriverForCurrentSupplier();
    }

    private function persistSource(): SupplierImportSource
    {
        if (! DatabaseSchema::hasTable('suppliers') || ! DatabaseSchema::hasTable('supplier_import_sources')) {
            throw new RuntimeException('Инфраструктура импортов еще не применена миграциями.');
        }

        $supplier = $this->currentSupplier();

        if (! $supplier instanceof Supplier) {
            throw new RuntimeException('Выберите поставщика перед сохранением варианта.');
        }

        $driver = $this->currentDriver();
        $payload = [
            'supplier_id' => $supplier->id,
            'source_name' => trim((string) data_get($this->data, 'source_name', '')),
            'driver_key' => $driver->key(),
            'profile_key' => $driver->profileKey(),
            'source_is_active' => (bool) data_get($this->data, 'source_is_active', true),
            'source_settings' => $driver->normalizeSettings(
                is_array(data_get($this->data, 'source_settings')) ? data_get($this->data, 'source_settings') : [],
            ),
        ];

        $sourceId = $this->normalizeNullableInt(data_get($this->data, 'supplier_import_source_id'));

        $validator = Validator::make($payload, [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'source_name' => ['required', 'string', 'max:160'],
            'driver_key' => ['required', 'string', Rule::in(array_keys($this->availableDriverOptions()))],
            'profile_key' => ['required', 'string', 'max:120'],
            'source_is_active' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($payload, $sourceId, $supplier): void {
            $exists = SupplierImportSource::query()
                ->where('supplier_id', $supplier->id)
                ->where('name', $payload['source_name'])
                ->when($sourceId !== null, fn ($query) => $query->where('id', '!=', $sourceId))
                ->exists();

            if ($exists) {
                $validator->errors()->add('source_name', 'У этого поставщика уже есть вариант импорта с таким названием.');
            }
        });

        $validator->validate();

        $source = $sourceId !== null
            ? SupplierImportSource::query()
                ->whereKey($sourceId)
                ->where('supplier_id', $supplier->id)
                ->first()
            : null;

        $source ??= new SupplierImportSource;
        $source->fill([
            'supplier_id' => $payload['supplier_id'],
            'name' => $payload['source_name'],
            'driver_key' => $payload['driver_key'],
            'profile_key' => $payload['profile_key'],
            'settings' => $payload['source_settings'],
            'is_active' => $payload['source_is_active'],
            'sort' => $source->exists ? (int) $source->sort : 0,
        ]);
        $source->save();

        $this->data['supplier_import_source_id'] = $source->id;
        $this->data['driver_key'] = $source->driver_key;
        $this->data['source_settings'] = $payload['source_settings'];
        $this->form->fill($this->data);

        return $source->fresh(['supplier']) ?? $source;
    }

    /**
     * @return array<int, string>
     */
    private function supplierDeletionBlockers(Supplier $supplier): array
    {
        $blockers = [];

        if (DatabaseSchema::hasTable('product_supplier_references') && $supplier->productReferences()->exists()) {
            $blockers[] = 'товарные привязки: '.$supplier->productReferences()->count();
        }

        if (DatabaseSchema::hasTable('import_runs') && $supplier->importRuns()->exists()) {
            $blockers[] = 'запуски импорта: '.$supplier->importRuns()->count();
        }

        return $blockers;
    }

    private function hasActiveImportRun(): bool
    {
        $source = $this->currentSource();

        return $source instanceof SupplierImportSource
            && $this->hasActiveRunForType($this->currentDriver()->importRunType(), $source);
    }

    private function hasActiveDeactivationRun(): bool
    {
        $source = $this->currentSource();
        $runType = $this->currentDriver()->deactivationRunType();

        return $source instanceof SupplierImportSource
            && is_string($runType)
            && $runType !== ''
            && $this->hasActiveRunForType($runType, $source);
    }

    private function hasActiveRunForType(string $type, SupplierImportSource $source): bool
    {
        return ImportRun::query()
            ->where('type', $type)
            ->where('supplier_import_source_id', $source->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    private function hasRecentDeactivationDryRun(SupplierImportSource $source, string $type): bool
    {
        $siteCategoryId = $this->normalizeNullableInt(data_get($this->data, 'deactivation.site_category_id'));

        if ($siteCategoryId === null) {
            return false;
        }

        return ImportRun::query()
            ->where('type', $type)
            ->where('supplier_import_source_id', $source->id)
            ->where('supplier_id', $source->supplier_id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->contains(function (ImportRun $run) use ($siteCategoryId): bool {
                return $this->normalizeNullableInt(data_get($run->columns, 'site_category_id')) === $siteCategoryId
                    && (string) data_get($run->totals, '_meta.mode') === 'dry-run'
                    && in_array((string) $run->status, ['completed', 'applied', 'dry_run'], true);
            });
    }

    /**
     * @return array<string, string>
     */
    private function syncScenarioOptions(): array
    {
        return [
            'standard' => 'Стандартный (создавать + обновлять)',
            'new_only' => 'Только новые товары',
            'custom' => 'Пользовательский',
        ];
    }

    private function syncScenarioSummary(string $scenario): string
    {
        return match ($scenario) {
            'new_only' => 'Создает только новые товары, существующие не обновляет.',
            'custom' => 'Используются ручные флаги создания и обновления ниже.',
            default => 'Создает новые и обновляет существующие товары без деактивации отсутствующих.',
        };
    }

    private function applySyncScenario(string $scenario): void
    {
        if (! is_array($this->data)) {
            return;
        }

        $this->isSyncScenarioInternalUpdate = true;

        $runtime = is_array($this->data['runtime'] ?? null) ? $this->data['runtime'] : $this->defaultRuntime();

        if ($scenario === 'new_only') {
            $runtime['create_missing'] = true;
            $runtime['update_existing'] = false;
            $runtime['skip_existing'] = true;
        } elseif ($scenario === 'standard') {
            $runtime['create_missing'] = true;
            $runtime['update_existing'] = true;
            $runtime['skip_existing'] = false;
        }

        $runtime['sync_scenario'] = $scenario;

        $this->data['runtime'] = $runtime;
        $this->isSyncScenarioInternalUpdate = false;
        $this->form->fill($this->data);
    }

    private function updateSyncScenarioFromFlags(): void
    {
        if ($this->isSyncScenarioInternalUpdate || ! is_array($this->data)) {
            return;
        }

        $runtime = $this->normalizedRuntime();
        $scenario = 'custom';

        if (
            $runtime['create_missing']
            && $runtime['update_existing']
            && ! $runtime['skip_existing']
        ) {
            $scenario = 'standard';
        } elseif (
            $runtime['create_missing']
            && ! $runtime['update_existing']
            && $runtime['skip_existing']
        ) {
            $scenario = 'new_only';
        }

        $this->data['runtime']['sync_scenario'] = $scenario;
        $this->form->fill($this->data);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'vactool_products' => 'Vactool',
            'metalmaster_products' => 'Metalmaster',
            'metaltec_products' => 'Metaltec',
            'yandex_market_feed_products' => 'Yandex Market Feed',
            'yandex_market_feed_deactivation' => 'Деактивация Yandex Feed',
            default => $type !== '' ? $type : 'unknown',
        };
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = (int) trim($value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = is_string($value) ? str_replace(',', '.', trim($value)) : $value;

        if (! is_numeric($normalized)) {
            return null;
        }

        $parsed = (float) $normalized;

        return $parsed > 0 ? $parsed : null;
    }
}
