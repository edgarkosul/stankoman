<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunYandexMarketFeedImportJob;
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
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;
use UnitEnum;

class YandexMarketFeedImport extends Page implements HasForms
{
    use InteractsWithForms;

    private const DISPLAY_TIMEZONE = 'Europe/Moscow';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Импорт Yandex Feed';

    protected static ?string $title = 'Импорт товаров из Yandex Market Feed';

    protected string $view = 'filament.pages.yandex-market-feed-import';

    /** @var array{
     *     supplier_id: int|null,
     *     source_mode: string,
     *     source_url: string,
     *     source_upload: TemporaryUploadedFile|array<int|string, TemporaryUploadedFile|string>|string|null,
     *     source_history_id: int|null,
     *     sync_scenario: string,
     *     category_id: int|null,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     publish: bool,
     *     download_images: bool,
     *     force_media_recheck: bool,
     *     skip_existing: bool,
     *     show_samples: int,
     *     create_missing: bool,
     *     update_existing: bool
     * }|null
     */
    public ?array $data = null;

    private bool $isSyncScenarioInternalUpdate = false;

    /** @var array<int, string> */
    public array $parsedCategories = [];

    /** @var array<int, array{id: int, name: string, parent_id: int|null, depth: int, is_leaf: bool, tree_name: string}> */
    public array $parsedCategoryTree = [];

    /** @var array<int, true> */
    public array $leafCategoryIds = [];

    public ?string $categoriesLoadedAt = null;

    public ?string $categoriesLoadedSource = null;

    public ?string $categoriesLoadedSourceKey = null;

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
            FormAction::make('deactivate')
                ->label('Деактивация товаров')
                ->icon('heroicon-o-power')
                ->color('gray')
                ->url(YandexMarketFeedDeactivate::getUrl()),
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
                    ->description('Выберите бизнес-поставщика, в рамках которого будут создаваться и обновляться связи товаров с feed.')
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
                            ->createOptionUsing(fn (array $data): int => $this->createSupplierFromData($data))
                            ->hintIcon(
                                Heroicon::InformationCircle,
                                'Этот поставщик используется для идентичности imported items. Если у разных поставщиков одинаковые external_id, они не пересекутся.',
                            ),
                    ]),
                Section::make('Источник Yandex Market Feed')
                    ->description('Можно запустить импорт всего фида или в два этапа: сначала загрузить категории, затем выбрать одну категорию для прогона.')
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
                            ->maxSize(YandexMarketFeedSourceHistoryService::maxUploadSizeKilobytes())
                            ->preserveFilenames()
                            ->disk('local')
                            ->directory(YandexMarketFeedSourceHistoryService::temporaryUploadDirectory())
                            ->visibility('private')
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $storedPath = $this->resolveStoredFeedUploadPath($state);

                                if ($storedPath !== null) {
                                    $set('source_upload', [$storedPath]);
                                }
                            })
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
                        Actions::make([
                            FormAction::make('load_categories')
                                ->label('Загрузить категории <category>')
                                ->icon('heroicon-o-list-bullet')
                                ->color('gray')
                                ->action('loadFeedCategories'),
                        ]),
                        Select::make('category_id')
                            ->label('Категория для прогона (опционально)')
                            ->placeholder('Весь фид (без фильтра)')
                            ->searchable()
                            ->native(false)
                            ->options(fn (): array => $this->categoryOptions(limit: 100))
                            ->getSearchResultsUsing(fn (string $search): array => $this->categoryOptions(search: $search, limit: 100))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->categoryOptionLabel($value))
                            ->hintIcon(
                                Heroicon::InformationCircle,
                                'Сначала нажмите "Загрузить категории <category>", затем выберите категорию. Для родительской категории будут импортированы товары из всех дочерних. Оставьте пустым для импорта всего фида.',
                            ),
                    ]),
                Section::make('Параметры run')
                    ->schema([
                        TextInput::make('limit')
                            ->label('Лимит offer (0 = все)')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('timeout')
                            ->label('Таймаут запроса, сек')
                            ->numeric()
                            ->integer()
                            ->minValue(1),
                        TextInput::make('delay_ms')
                            ->label('Задержка между записями, мс')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('show_samples')
                            ->label('Примеры строк в dry-run')
                            ->numeric()
                            ->integer()
                            ->minValue(0),
                        Select::make('sync_scenario')
                            ->label('Сценарий импорта')
                            ->options([
                                'standard' => 'Стандартный (создавать + обновлять)',
                                'new_only' => 'Только новые товары',
                                'custom' => 'Пользовательский (расширенные настройки)',
                            ])
                            ->default('standard')
                            ->native(false)
                            ->live()
                            ->helperText(fn (Get $get): string => $this->syncScenarioSummary((string) $get('sync_scenario'))),
                        Toggle::make('publish')
                            ->label('Публиковать импортированные товары'),
                        Toggle::make('download_images')
                            ->label('Скачивать изображения')
                            ->default(true),
                        Toggle::make('force_media_recheck')
                            ->label('Обновлять картинки, даже если ссылка не изменилась')
                            ->helperText('Используйте это, если поставщик может заменить изображение по старой ссылке. Может немного замедлить импорт.'),
                    ]),
                Section::make('Расширенные настройки (технические)')
                    ->description('Изменяйте только при точном понимании последствий. Деактивация отсутствующих товаров выполняется на отдельной странице.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Toggle::make('create_missing')
                            ->label('Создавать новые товары')
                            ->default(true),
                        Toggle::make('update_existing')
                            ->label('Обновлять существующие товары')
                            ->helperText('При включенном "Пропускать существующие" обновления не выполняются.')
                            ->disabled(fn (Get $get): bool => (bool) $get('skip_existing'))
                            ->default(true),
                        Toggle::make('skip_existing')
                            ->label('Пропускать существующие (prefilter)')
                            ->helperText('Оптимизация для режима "только новые".')
                            ->live(),
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

    public function updatedDataSourceMode(): void
    {
        $this->resetCategoriesIfSourceChanged();
    }

    public function updatedDataSourceUrl(): void
    {
        $this->resetCategoriesIfSourceChanged();
    }

    public function updatedDataSourceUpload(): void
    {
        $this->resetCategoriesIfSourceChanged();
    }

    public function updatedDataSourceHistoryId(): void
    {
        $this->resetCategoriesIfSourceChanged();
    }

    public function updatedDataSyncScenario(mixed $value): void
    {
        if ($this->isSyncScenarioInternalUpdate) {
            return;
        }

        $scenario = is_string($value) ? trim($value) : '';

        if ($scenario === 'custom') {
            return;
        }

        $this->applySyncScenario($scenario);
    }

    public function updatedDataCreateMissing(): void
    {
        if ($this->isSyncScenarioInternalUpdate) {
            return;
        }

        $this->syncScenarioFromFlags();
    }

    public function updatedDataUpdateExisting(): void
    {
        if ($this->isSyncScenarioInternalUpdate) {
            return;
        }

        $this->syncScenarioFromFlags();
    }

    public function updatedDataSkipExisting(mixed $value): void
    {
        if ($this->isSyncScenarioInternalUpdate) {
            return;
        }

        $skipExisting = (bool) $value;

        if ($skipExisting && is_array($this->data)) {
            $this->data['update_existing'] = false;
        }

        $this->syncScenarioFromFlags();
    }

    private function applySyncScenario(string $scenario): void
    {
        if (! is_array($this->data)) {
            return;
        }

        $this->isSyncScenarioInternalUpdate = true;

        if ($scenario === 'new_only') {
            $this->data['create_missing'] = true;
            $this->data['update_existing'] = false;
            $this->data['skip_existing'] = true;
            $this->data['sync_scenario'] = 'new_only';
            $this->isSyncScenarioInternalUpdate = false;

            return;
        }
        $this->data['create_missing'] = true;
        $this->data['update_existing'] = true;
        $this->data['skip_existing'] = false;
        $this->data['sync_scenario'] = 'standard';

        $this->isSyncScenarioInternalUpdate = false;
    }

    private function syncScenarioFromFlags(): void
    {
        if (! is_array($this->data)) {
            return;
        }

        $scenario = $this->resolveSyncScenarioFromFlags();

        if ((string) ($this->data['sync_scenario'] ?? '') === $scenario) {
            return;
        }

        $this->isSyncScenarioInternalUpdate = true;
        $this->data['sync_scenario'] = $scenario;
        $this->isSyncScenarioInternalUpdate = false;
    }

    private function resolveSyncScenarioFromFlags(): string
    {
        $createMissing = (bool) ($this->data['create_missing'] ?? true);
        $updateExisting = (bool) ($this->data['update_existing'] ?? true);
        $skipExisting = (bool) ($this->data['skip_existing'] ?? false);

        if ($createMissing && $updateExisting && ! $skipExisting) {
            return 'standard';
        }

        if ($createMissing && ! $updateExisting && $skipExisting) {
            return 'new_only';
        }

        return 'custom';
    }

    private function syncScenarioSummary(string $scenario): string
    {
        if ($scenario === 'new_only') {
            return 'Создаются только новые товары. Существующие позиции пропускаются и не обновляются.';
        }

        if ($scenario === 'custom') {
            return 'Пользовательская комбинация create/update/skip из раздела "Расширенные настройки". Деактивация вынесена в отдельный сценарий.';
        }

        return 'Создаются новые и обновляются существующие товары. Деактивация отсутствующих выполняется отдельно.';
    }

    public function loadFeedCategories(): void
    {
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
            $categories = app(YandexMarketFeedImportService::class)->listCategoryNodes([
                'source' => $resolvedSource['source'],
                'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            ]);
            $resolvedSource = $this->rememberSuccessfulSource($resolvedSource);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Не удалось загрузить категории')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->parsedCategories = [];
        $normalizedCategories = [];

        foreach ($categories as $rawCategory) {
            if (! is_array($rawCategory)) {
                continue;
            }

            $categoryId = $this->normalizeNullableInt($rawCategory['id'] ?? null);

            if ($categoryId === null) {
                continue;
            }

            $name = trim((string) ($rawCategory['name'] ?? ''));
            $parentId = $this->normalizeNullableInt($rawCategory['parent_id'] ?? $rawCategory['parentId'] ?? null);

            if ($parentId === $categoryId) {
                $parentId = null;
            }

            $normalizedCategories[$categoryId] = [
                'id' => $categoryId,
                'name' => $name !== '' ? $name : ('Категория #'.$categoryId),
                'parent_id' => $parentId,
            ];
            $this->parsedCategories[$categoryId] = $name !== '' ? $name : ('Категория #'.$categoryId);
        }

        [$this->parsedCategoryTree, $this->leafCategoryIds] = $this->buildCategoryTree($normalizedCategories);

        $selectedCategoryId = $this->normalizeNullableInt($this->data['category_id'] ?? null);

        if ($selectedCategoryId !== null && ! isset($this->parsedCategoryTree[$selectedCategoryId])) {
            $this->data['category_id'] = null;
        }

        $this->categoriesLoadedAt = now()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s');
        $this->categoriesLoadedSource = $resolvedSource['source_label'];
        $this->categoriesLoadedSourceKey = $resolvedSource['source_key'];

        Notification::make()
            ->title('Категории загружены')
            ->body(
                'Найдено категорий: '.count($this->parsedCategories)
                .'. Листовых: '.count($this->leafCategoryIds).'.'
            )
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

    public function stopActiveImport(): void
    {
        $run = $this->resolveActiveRun();
        $runs = app(ImportRunOrchestrator::class);

        if (! $run) {
            Notification::make()
                ->title('Активный запуск не найден')
                ->body('Для Yandex Market Feed нет запуска со статусом "В ожидании" или "Выполняется".')
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
                ->body('Дождитесь завершения текущего запуска Yandex Market Feed или остановите его.')
                ->warning()
                ->send();

            $this->refreshLastSavedRun();

            return;
        }

        if (
            $write
            && ! ((bool) ($this->data['create_missing'] ?? true))
            && ! ((bool) ($this->data['update_existing'] ?? true))
        ) {
            Notification::make()
                ->title('Запуск не изменит данные')
                ->body('Одновременно отключены создание новых и обновление существующих товаров.')
                ->warning()
                ->send();

            return;
        }

        $supplierId = $this->normalizeNullableInt($this->data['supplier_id'] ?? null);

        if ($supplierId === null || ! Supplier::query()->whereKey($supplierId)->exists()) {
            Notification::make()
                ->title('Выберите поставщика')
                ->body('Перед запуском dry-run или импорта выберите существующего поставщика либо создайте нового.')
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
                ->title('Фид не прошел предварительную валидацию')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $options = $this->buildOptions($write, $resolvedSource);
        $mode = $write ? 'write' : 'dry-run';
        $runs = app(ImportRunOrchestrator::class);
        $run = $runs->start(
            type: 'yandex_market_feed_products',
            columns: $options,
            mode: $mode,
            sourceFilename: $options['source_label'] ?: $options['source'],
            userId: Auth::id(),
        );

        $sourceId = $this->normalizeNullableInt($options['source_id'] ?? null);

        if ($sourceId !== null) {
            app(YandexMarketFeedSourceHistoryService::class)->markUsedById($sourceId, $run->id);
        }

        RunYandexMarketFeedImportJob::dispatch($run->id, $options, $write)->afterCommit();

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
     *     category_id: int|null,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     force_media_recheck: bool,
     *     skip_existing: bool,
     *     show_samples: int,
     *     mode: string,
     *     finalize_missing: bool,
     *     create_missing: bool,
     *     update_existing: bool
     * }
     */
    private function buildOptions(bool $write, array $resolvedSource): array
    {
        $supplierId = $this->normalizeNullableInt($this->data['supplier_id'] ?? null);
        $supplierName = $supplierId !== null
            ? trim((string) Supplier::query()->whereKey($supplierId)->value('name'))
            : '';

        return [
            'source' => (string) $resolvedSource['source'],
            'source_type' => (string) ($resolvedSource['source_type'] ?? YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL),
            'source_id' => $this->normalizeNullableInt($resolvedSource['source_id'] ?? null),
            'source_label' => (string) ($resolvedSource['source_label'] ?? $resolvedSource['source']),
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
            'category_id' => $this->normalizeNullableInt($this->data['category_id'] ?? null),
            'limit' => max(0, (int) ($this->data['limit'] ?? 0)),
            'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) ($this->data['delay_ms'] ?? 0)),
            'write' => $write,
            'publish' => (bool) ($this->data['publish'] ?? false),
            'download_images' => (bool) ($this->data['download_images'] ?? true),
            'force_media_recheck' => (bool) ($this->data['force_media_recheck'] ?? false),
            'skip_existing' => (bool) ($this->data['skip_existing'] ?? false),
            'show_samples' => max(0, (int) ($this->data['show_samples'] ?? 3)),
            'mode' => 'partial_import',
            'finalize_missing' => false,
            'create_missing' => (bool) ($this->data['create_missing'] ?? true),
            'update_existing' => (bool) ($this->data['update_existing'] ?? true),
        ];
    }

    public function refreshLastSavedRun(): void
    {
        if (! DatabaseSchema::hasTable('import_runs')) {
            $this->lastSavedRun = null;
            $this->lastSavedIssues = [];

            return;
        }

        $runQuery = ImportRun::query()->where('type', 'yandex_market_feed_products');

        if ($this->lastRunId !== null) {
            $runQuery->whereKey($this->lastRunId);
        }

        $run = $runQuery->latest('id')->first();

        if (! $run) {
            $run = ImportRun::query()
                ->where('type', 'yandex_market_feed_products')
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
            'is_running' => (bool) ($meta['is_running'] ?? in_array($run->status, ['pending', 'running'], true)),
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
            'supplier_label' => trim((string) ($columns['supplier_name'] ?? '')),
            'source' => trim((string) ($columns['source_label'] ?? $columns['source'] ?? '')),
            'category_id' => $this->normalizeNullableInt($columns['category_id'] ?? null),
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
            'category_id' => null,
            'limit' => 0,
            'timeout' => 25,
            'delay_ms' => 0,
            'sync_scenario' => 'standard',
            'publish' => false,
            'download_images' => true,
            'force_media_recheck' => false,
            'skip_existing' => false,
            'show_samples' => 3,
            'create_missing' => true,
            'update_existing' => true,
        ];
    }

    private function resetCategoriesIfSourceChanged(): void
    {
        if ($this->resolveSourceKeyFromState() === $this->categoriesLoadedSourceKey) {
            return;
        }

        $this->parsedCategories = [];
        $this->parsedCategoryTree = [];
        $this->leafCategoryIds = [];
        $this->categoriesLoadedAt = null;
        $this->categoriesLoadedSource = null;
        $this->categoriesLoadedSourceKey = null;

        if (is_array($this->data)) {
            $this->data['category_id'] = null;
        }
    }

    private function resolveSourceKeyFromState(): string
    {
        $mode = (string) ($this->data['source_mode'] ?? 'url');

        if ($mode === 'history') {
            return 'history|'.(string) ($this->normalizeNullableInt($this->data['source_history_id'] ?? null) ?? '');
        }

        if ($mode === 'upload') {
            return 'upload|'.(string) ($this->resolveStoredFeedUploadPath($this->data['source_upload'] ?? null) ?? '');
        }

        return 'url|'.trim((string) ($this->data['source_url'] ?? ''));
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

        $resolvedSource = $this->applyRememberedSourceToState($resolvedSource, $record);
        $resolvedSource['source_key'] = $this->resolveSourceKeyFromState();

        return $resolvedSource;
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

        if (is_string($value) && trim($value) !== '' && $this->looksLikeStoredPath($value)) {
            return ltrim(trim($value), '/');
        }

        if (is_array($value)) {
            foreach (['path', 'stored_path', 'storedPath', 'relative_path', 'relativePath'] as $key) {
                $candidate = $value[$key] ?? null;

                if (is_string($candidate) && trim($candidate) !== '' && $this->looksLikeStoredPath($candidate)) {
                    return ltrim(trim($candidate), '/');
                }
            }

            $first = reset($value);

            if ($first instanceof TemporaryUploadedFile) {
                $storedPath = $first->store(path: YandexMarketFeedSourceHistoryService::temporaryUploadDirectory(), options: 'local');

                return is_string($storedPath) && $storedPath !== '' ? $storedPath : null;
            }

            if (is_string($first) && trim($first) !== '' && $this->looksLikeStoredPath($first)) {
                return ltrim(trim($first), '/');
            }

            foreach ($value as $nestedValue) {
                $nestedPath = $this->resolveStoredFeedUploadPath($nestedValue);

                if ($nestedPath !== null) {
                    return $nestedPath;
                }
            }

            return null;
        }

        return null;
    }

    private function looksLikeStoredPath(string $candidate): bool
    {
        return str_contains($candidate, '/') || str_contains($candidate, '\\');
    }

    /**
     * @return array<string, string>
     */
    private function categoryOptions(?string $search = null, int $limit = 100): array
    {
        if ($this->parsedCategoryTree === []) {
            return [];
        }

        $options = [];
        $needle = $search !== null ? mb_strtolower(trim($search)) : null;

        foreach ($this->parsedCategoryTree as $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = (int) ($category['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $name = trim((string) ($category['name'] ?? ''));
            $depth = max(0, (int) ($category['depth'] ?? 0));
            $label = $this->categoryLabel($id, $name, $depth);

            if ($needle !== null && $needle !== '') {
                $idMatches = str_contains((string) $id, $needle);
                $nameMatches = str_contains(mb_strtolower($label), $needle);

                if (! $idMatches && ! $nameMatches) {
                    continue;
                }
            }

            $options[(string) $id] = $label;

            if (count($options) >= $limit) {
                break;
            }
        }

        return $options;
    }

    private function categoryOptionLabel(mixed $value): ?string
    {
        $categoryId = $this->normalizeNullableInt($value);

        if ($categoryId === null) {
            return null;
        }

        $category = $this->parsedCategoryTree[$categoryId] ?? null;

        if (! is_array($category)) {
            $categoryName = $this->parsedCategories[$categoryId] ?? null;

            if (! is_string($categoryName)) {
                return (string) $categoryId;
            }

            return $this->categoryLabel($categoryId, trim($categoryName));
        }

        $categoryName = trim((string) ($category['name'] ?? ''));
        $depth = max(0, (int) ($category['depth'] ?? 0));

        return $this->categoryLabel($categoryId, $categoryName, $depth);
    }

    private function categoryLabel(int $categoryId, string $categoryName, int $depth = 0): string
    {
        $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';

        if ($categoryName === '') {
            return $prefix.'['.$categoryId.']';
        }

        return $prefix.'['.$categoryId.'] '.$categoryName;
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_id: int|null}>  $categories
     * @return array{
     *     0: array<int, array{id: int, name: string, parent_id: int|null, depth: int, is_leaf: bool, tree_name: string}>,
     *     1: array<int, true>
     * }
     */
    private function buildCategoryTree(array $categories): array
    {
        if ($categories === []) {
            return [[], []];
        }

        $normalized = [];

        foreach ($categories as $categoryId => $category) {
            if (! is_array($category)) {
                continue;
            }

            $id = (int) ($category['id'] ?? $categoryId);

            if ($id <= 0) {
                continue;
            }

            $name = trim((string) ($category['name'] ?? ''));
            $parentId = $this->normalizeNullableInt($category['parent_id'] ?? null);

            $normalized[$id] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('Категория #'.$id),
                'parent_id' => $parentId,
            ];
        }

        if ($normalized === []) {
            return [[], []];
        }

        foreach ($normalized as $id => $category) {
            $parentId = $category['parent_id'];

            if ($parentId !== null && ! isset($normalized[$parentId])) {
                $normalized[$id]['parent_id'] = null;
            }
        }

        $childrenByParent = [];

        foreach ($normalized as $id => $category) {
            $parentId = $category['parent_id'] ?? 0;
            $childrenByParent[$parentId][] = $id;
        }

        $tree = [];
        $leafCategoryIds = [];
        $visited = [];

        $walk = function (int $categoryId, int $depth, array $path = []) use (&$walk, &$tree, &$leafCategoryIds, &$visited, $normalized, $childrenByParent): void {
            if (isset($visited[$categoryId])) {
                return;
            }

            if (isset($path[$categoryId])) {
                return;
            }

            $path[$categoryId] = true;
            $visited[$categoryId] = true;

            $category = $normalized[$categoryId];
            $children = $childrenByParent[$categoryId] ?? [];
            $isLeaf = $children === [];

            $tree[$categoryId] = [
                'id' => $categoryId,
                'name' => $category['name'],
                'parent_id' => $category['parent_id'],
                'depth' => $depth,
                'is_leaf' => $isLeaf,
                'tree_name' => ($depth > 0 ? str_repeat('— ', $depth) : '').$category['name'],
            ];

            if ($isLeaf) {
                $leafCategoryIds[$categoryId] = true;

                return;
            }

            foreach ($children as $childCategoryId) {
                $walk($childCategoryId, $depth + 1, $path);
            }
        };

        foreach ($normalized as $id => $category) {
            if (($category['parent_id'] ?? null) !== null) {
                continue;
            }

            $walk($id, 0);
        }

        foreach ($normalized as $id => $_category) {
            if (isset($visited[$id])) {
                continue;
            }

            $walk($id, 0);
        }

        return [$tree, $leafCategoryIds];
    }

    private function hasActiveRun(): bool
    {
        return $this->resolveActiveRun() !== null;
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

    private function resolveActiveRun(): ?ImportRun
    {
        if (! DatabaseSchema::hasTable('import_runs')) {
            return null;
        }

        $runQuery = ImportRun::query()
            ->where('type', 'yandex_market_feed_products')
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
