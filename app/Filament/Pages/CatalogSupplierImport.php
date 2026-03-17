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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Throwable;
use UnitEnum;

class CatalogSupplierImport extends Page implements HasForms
{
    use InteractsWithForms;

    private const DISPLAY_TIMEZONE = 'Europe/Moscow';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.catalog-supplier-import';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Импорт поставщиков';

    protected static ?string $title = 'Единый импорт поставщиков';

    /** @var array{
     *     supplier: string,
     *     scope: string,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     show_samples: int,
     *     sync_scenario: string,
     *     publish: bool,
     *     download_images: bool,
     *     force_media_recheck: bool,
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
        'scope' => '',
        'limit' => 0,
        'timeout' => 25,
        'delay_ms' => 250,
        'show_samples' => 3,
        'sync_scenario' => 'standard',
        'publish' => false,
        'download_images' => true,
        'force_media_recheck' => false,
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

    /** @var array<string, array{key: string, name: string, depth: int, is_leaf: bool, items_count: int, source_url: string}> */
    public array $parsedScopeTree = [];

    public ?string $scopesLoadedAt = null;

    public ?string $scopesLoadedSource = null;

    public ?string $scopesLoadedSupplier = null;

    private bool $isSyncScenarioInternalUpdate = false;

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
                Section::make('Поставщик')
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
                        Actions::make([
                            FormAction::make('load_supplier_scopes')
                                ->label('Загрузить разделы')
                                ->color('gray')
                                ->action('loadSupplierScopes')
                                ->visible(fn (Get $get): bool => $this->supportsSupplierScopes((string) $get('supplier'))),
                            FormAction::make('regenerate_supplier_scopes')
                                ->label('Перегенерировать разделы')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->action('regenerateSupplierScopes')
                                ->visible(fn (Get $get): bool => $this->canRegenerateSupplierScopes((string) $get('supplier'))),
                        ]),
                        Select::make('scope')
                            ->label(fn (Get $get): string => $this->scopeFieldLabel((string) $get('supplier')))
                            ->placeholder('Все разделы')
                            ->searchable()
                            ->native(false)
                            ->options(fn (): array => $this->scopeOptions(limit: 100))
                            ->getSearchResultsUsing(fn (string $search): array => $this->scopeOptions(search: $search, limit: 100))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->scopeOptionLabel($value))
                            ->optionsLimit(100)
                            ->hintIcon(Heroicon::InformationCircle, 'Показаны первые 100 разделов. Поиск работает по всему доступному списку.')
                            ->visible(fn (Get $get): bool => $this->supportsSupplierScopes((string) $get('supplier'))),
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
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => (string) $get('supplier') === 'metalmaster'),
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
                        Select::make('sync_scenario')
                            ->label('Сценарий импорта')
                            ->options([
                                'standard' => 'Стандартный (создавать + обновлять)',
                                'new_only' => 'Только новые товары',
                                'full_sync' => 'Полная сверка (деактивировать отсутствующие)',
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
                            ->label('Принудительно перепроверять медиа у донора')
                            ->helperText('Игнорирует TTL переиспользования: для каждого URL будет выполнена проверка изменения файла.'),
                    ]),
                Section::make('Расширенные настройки (технические)')
                    ->description('Изменяйте только при точном понимании последствий. Обычно достаточно выбрать сценарий импорта выше.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Select::make('mode')
                            ->label('Технический режим синхронизации')
                            ->options([
                                'partial_import' => 'partial_import',
                                'full_sync_authoritative' => 'full_sync_authoritative',
                            ])
                            ->default('partial_import')
                            ->live()
                            ->native(false),
                        Toggle::make('finalize_missing')
                            ->label('Деактивировать отсутствующие (Finalize missing)')
                            ->helperText('Срабатывает только вместе с mode=full_sync_authoritative.')
                            ->default(false),
                        Toggle::make('create_missing')
                            ->label('Создавать новые товары')
                            ->default(true),
                        Toggle::make('update_existing')
                            ->label('Обновлять существующие товары')
                            ->helperText('При включенном "Пропускать существующие" обновления не выполняются.')
                            ->disabled(fn (Get $get): bool => (bool) $get('skip_existing'))
                            ->default(true),
                        Toggle::make('skip_existing')
                            ->label('Пропускать уже существующие товары (prefilter)')
                            ->live(),
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
        $this->data['scope'] = '';
        $this->resetLoadedScopes($supplier);
        $this->syncScenarioFromFlags();
        $this->refreshLastSavedRun();
    }

    public function loadSupplierScopes(): void
    {
        $supplier = (string) ($this->data['supplier'] ?? 'vactool');

        if (! $this->supportsSupplierScopes($supplier)) {
            Notification::make()
                ->title('Разделы недоступны')
                ->body('Для выбранного поставщика список разделов пока не поддерживается.')
                ->warning()
                ->send();

            return;
        }

        try {
            $rows = $this->supplierScopeRows($supplier);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Не удалось загрузить разделы')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($rows === []) {
            $this->resetLoadedScopes($supplier);

            Notification::make()
                ->title('Разделы не найдены')
                ->body('Список разделов пуст. Проверьте источник и попробуйте перегенерацию.')
                ->warning()
                ->send();

            return;
        }

        $this->parsedScopeTree = $rows;
        $this->scopesLoadedAt = now()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s');
        $this->scopesLoadedSource = $this->scopeSourceLabel($supplier);
        $this->scopesLoadedSupplier = $supplier;

        $selectedScope = trim((string) ($this->data['scope'] ?? ''));

        if ($selectedScope !== '' && ! isset($this->parsedScopeTree[$selectedScope])) {
            $this->data['scope'] = '';
        }

        Notification::make()
            ->title('Разделы загружены')
            ->body('Найдено разделов: '.count($this->parsedScopeTree).'.')
            ->success()
            ->send();
    }

    public function regenerateSupplierScopes(): void
    {
        $supplier = (string) ($this->data['supplier'] ?? 'vactool');

        if (! $this->canRegenerateSupplierScopes($supplier)) {
            Notification::make()
                ->title('Перегенерация недоступна')
                ->body('Для выбранного поставщика перегенерация разделов пока не поддерживается.')
                ->warning()
                ->send();

            return;
        }

        if ($supplier === 'metalmaster') {
            try {
                $exitCode = Artisan::call('parser:sitemap-buckets', [
                    '--no-interaction' => true,
                ]);
            } catch (Throwable $exception) {
                Notification::make()
                    ->title('Не удалось перегенерировать разделы')
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

            $this->data['scope'] = '';
            $this->resetLoadedScopes($supplier);

            $output = trim(Artisan::output());

            Notification::make()
                ->title('Разделы перегенерированы')
                ->body($output !== '' ? $output : 'Команда parser:sitemap-buckets выполнена успешно.')
                ->success()
                ->send();

            return;
        }
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

    public function updatedDataMode(mixed $value): void
    {
        if ($this->isSyncScenarioInternalUpdate) {
            return;
        }

        $mode = is_string($value) ? trim($value) : 'partial_import';

        if ($mode !== 'full_sync_authoritative' && is_array($this->data)) {
            $this->data['finalize_missing'] = false;
        }

        $this->syncScenarioFromFlags();
    }

    public function updatedDataFinalizeMissing(): void
    {
        if ($this->isSyncScenarioInternalUpdate) {
            return;
        }

        $this->syncScenarioFromFlags();
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

            $this->refreshLastSavedRun();

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
            sourceFilename: (string) ($options['sitemap'] ?? $options['buckets_file'] ?? null),
            userId: Auth::id(),
            meta: [
                'supplier' => $supplier,
                'profile' => $this->defaultProfileForSupplier($supplier),
                'scope' => trim((string) ($this->data['scope'] ?? '')),
            ],
        );

        if ($supplier === 'vactool') {
            RunVactoolProductImportJob::dispatch($run->id, $options, $write)->afterCommit();
        } else {
            RunMetalmasterProductImportJob::dispatch($run->id, $options, $write)->afterCommit();
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

    private function applySyncScenario(string $scenario): void
    {
        if (! is_array($this->data)) {
            return;
        }

        $this->isSyncScenarioInternalUpdate = true;

        if ($scenario === 'new_only') {
            $this->data['mode'] = 'partial_import';
            $this->data['finalize_missing'] = false;
            $this->data['create_missing'] = true;
            $this->data['update_existing'] = false;
            $this->data['skip_existing'] = true;
            $this->data['sync_scenario'] = 'new_only';
            $this->isSyncScenarioInternalUpdate = false;

            return;
        }

        if ($scenario === 'full_sync') {
            $this->data['mode'] = 'full_sync_authoritative';
            $this->data['finalize_missing'] = true;
            $this->data['create_missing'] = true;
            $this->data['update_existing'] = true;
            $this->data['skip_existing'] = false;
            $this->data['sync_scenario'] = 'full_sync';
            $this->isSyncScenarioInternalUpdate = false;

            return;
        }

        $this->data['mode'] = 'partial_import';
        $this->data['finalize_missing'] = false;
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
        $mode = (string) ($this->data['mode'] ?? 'partial_import');
        $finalizeMissing = (bool) ($this->data['finalize_missing'] ?? false);
        $createMissing = (bool) ($this->data['create_missing'] ?? true);
        $updateExisting = (bool) ($this->data['update_existing'] ?? true);
        $skipExisting = (bool) ($this->data['skip_existing'] ?? false);

        if (
            $mode === 'partial_import'
            && ! $finalizeMissing
            && $createMissing
            && $updateExisting
            && ! $skipExisting
        ) {
            return 'standard';
        }

        if (
            $mode === 'partial_import'
            && ! $finalizeMissing
            && $createMissing
            && ! $updateExisting
            && $skipExisting
        ) {
            return 'new_only';
        }

        if (
            $mode === 'full_sync_authoritative'
            && $finalizeMissing
            && $createMissing
            && $updateExisting
            && ! $skipExisting
        ) {
            return 'full_sync';
        }

        return 'custom';
    }

    private function syncScenarioSummary(string $scenario): string
    {
        if ($scenario === 'new_only') {
            return 'Создаются только новые товары. Существующие позиции пропускаются и не обновляются.';
        }

        if ($scenario === 'full_sync') {
            return 'Создание + обновление + деактивация отсутствующих в источнике (в пределах выбранного scope).';
        }

        if ($scenario === 'custom') {
            return 'Пользовательская комбинация параметров из раздела "Расширенные настройки".';
        }

        return 'Создаются новые и обновляются существующие товары. Отсутствующие в источнике не деактивируются.';
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
            'scope' => trim((string) ($this->data['scope'] ?? '')),
            'limit' => max(0, (int) ($this->data['limit'] ?? 0)),
            'delay_ms' => max(0, (int) ($this->data['delay_ms'] ?? 250)),
            'publish' => (bool) ($this->data['publish'] ?? false),
            'download_images' => (bool) ($this->data['download_images'] ?? true),
            'force_media_recheck' => (bool) ($this->data['force_media_recheck'] ?? false),
            'skip_existing' => (bool) ($this->data['skip_existing'] ?? false),
            'show_samples' => max(0, (int) ($this->data['show_samples'] ?? 3)),
            'mode' => $mode,
            'finalize_missing' => (bool) ($this->data['finalize_missing'] ?? ($mode === 'full_sync_authoritative')),
            'create_missing' => (bool) ($this->data['create_missing'] ?? true),
            'update_existing' => (bool) ($this->data['update_existing'] ?? true),
            'error_threshold_count' => $this->normalizeNullableInt($this->data['error_threshold_count'] ?? null),
            'error_threshold_percent' => $this->normalizeNullableFloat($this->data['error_threshold_percent'] ?? null),
            'profile' => $this->defaultProfileForSupplier($supplier),
        ];

        if ($supplier === 'vactool') {
            return array_merge($commonOptions, [
                'sitemap' => app(VactoolSupplierProfile::class)->defaultSitemap(),
                'match' => app(VactoolSupplierProfile::class)->defaultUrlMatch(),
            ]);
        }

        if ($supplier === 'metalmaster') {
            return array_merge($commonOptions, [
                'buckets_file' => app(MetalmasterSupplierProfile::class)->defaultBucketsFile(),
                'bucket' => trim((string) ($this->data['scope'] ?? '')),
                'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            ]);
        }

        return array_merge($commonOptions, [
            'sitemap' => app(VactoolSupplierProfile::class)->defaultSitemap(),
            'match' => app(VactoolSupplierProfile::class)->defaultUrlMatch(),
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

        $columns = is_array($run->columns) ? $run->columns : [];
        $processed = (int) ($totals['scanned'] ?? 0);
        $foundUrls = (int) ($meta['found_urls'] ?? 0);
        $progressPercent = $foundUrls > 0
            ? max(0, min(100, (int) floor(($processed / $foundUrls) * 100)))
            : 0;

        $this->lastSavedRun = [
            'id' => $run->id,
            'supplier' => $supplier,
            'supplier_label' => $this->supplierLabel($supplier),
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
            'scope' => (string) ($columns['bucket'] ?? $columns['scope'] ?? ''),
            'buckets_file' => (string) ($columns['buckets_file'] ?? ''),
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
     * @return array<string, string>
     */
    private function scopeOptions(?string $search = null, int $limit = 100): array
    {
        $supplier = (string) ($this->data['supplier'] ?? 'vactool');

        if (! $this->supportsSupplierScopes($supplier)) {
            return [];
        }

        $rows = $this->scopeRowsForOptions($supplier);
        $needle = $search !== null ? mb_strtolower(trim($search)) : null;
        $options = [];

        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $label = $this->scopeLabel($row);

            if ($needle !== null && $needle !== '') {
                $inKey = str_contains(mb_strtolower($key), $needle);
                $inLabel = str_contains(mb_strtolower($label), $needle);
                $inUrl = str_contains(mb_strtolower((string) ($row['source_url'] ?? '')), $needle);

                if (! $inKey && ! $inLabel && ! $inUrl) {
                    continue;
                }
            }

            $options[$key] = $label;

            if (count($options) >= $limit) {
                break;
            }
        }

        return $options;
    }

    private function scopeOptionLabel(mixed $value): ?string
    {
        $scopeKey = trim((string) $value);

        if ($scopeKey === '') {
            return null;
        }

        $supplier = (string) ($this->data['supplier'] ?? 'vactool');

        foreach ($this->scopeRowsForOptions($supplier) as $row) {
            if ((string) ($row['key'] ?? '') === $scopeKey) {
                return $this->scopeLabel($row);
            }
        }

        return $scopeKey;
    }

    /**
     * @return array<int, array{key: string, name: string, depth: int, is_leaf: bool, items_count: int, source_url: string}>
     */
    private function scopeRowsForOptions(string $supplier): array
    {
        if ($this->scopesLoadedSupplier === $supplier && $this->parsedScopeTree !== []) {
            return array_values($this->parsedScopeTree);
        }

        return array_values($this->supplierScopeRows($supplier));
    }

    private function scopeLabel(array $row): string
    {
        $depth = max(0, (int) ($row['depth'] ?? 0));
        $name = trim((string) ($row['name'] ?? ''));
        $key = trim((string) ($row['key'] ?? ''));
        $itemsCount = max(0, (int) ($row['items_count'] ?? 0));

        $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
        $label = $prefix.($name !== '' ? $name : $key);

        if ($itemsCount > 0) {
            $label .= ' ('.$itemsCount.')';
        }

        return $label;
    }

    private function supportsSupplierScopes(string $supplier): bool
    {
        return $supplier === 'metalmaster';
    }

    private function canRegenerateSupplierScopes(string $supplier): bool
    {
        return $supplier === 'metalmaster';
    }

    private function scopeFieldLabel(string $supplier): string
    {
        return match ($supplier) {
            'metalmaster' => 'Категория поставщика (пусто = все категории)',
            default => 'Раздел поставщика (пусто = все разделы)',
        };
    }

    private function scopeSourceLabel(string $supplier): ?string
    {
        return match ($supplier) {
            'metalmaster' => app(MetalmasterSupplierProfile::class)->defaultBucketsFile(),
            default => null,
        };
    }

    private function resetLoadedScopes(string $supplier): void
    {
        $this->parsedScopeTree = [];
        $this->scopesLoadedAt = null;
        $this->scopesLoadedSource = $this->scopeSourceLabel($supplier);
        $this->scopesLoadedSupplier = $supplier;
    }

    /**
     * @return array<string, array{key: string, name: string, depth: int, is_leaf: bool, items_count: int, source_url: string}>
     */
    private function supplierScopeRows(string $supplier): array
    {
        if ($supplier === 'metalmaster') {
            return $this->metalmasterScopeRows();
        }

        return [];
    }

    /**
     * @return array<string, array{key: string, name: string, depth: int, is_leaf: bool, items_count: int, source_url: string}>
     */
    private function metalmasterScopeRows(): array
    {
        $sourceFile = app(MetalmasterSupplierProfile::class)->defaultBucketsFile();
        $raw = @file_get_contents($sourceFile);

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            $rows = array_values(array_filter($decoded, 'is_array'));
        } else {
            $rows = $decoded['buckets'] ?? [];
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        }

        $scopeRows = [];

        foreach ($rows as $row) {
            $key = trim((string) ($row['bucket'] ?? ''));

            if ($key === '') {
                continue;
            }

            $scopeRows[$key] = [
                'key' => $key,
                'name' => $key,
                'depth' => 0,
                'is_leaf' => true,
                'items_count' => max(0, (int) ($row['products_count'] ?? 0)),
                'source_url' => trim((string) ($row['category_url'] ?? '')),
            ];
        }

        uasort($scopeRows, function (array $left, array $right): int {
            $countDiff = (int) ($right['items_count'] ?? 0) <=> (int) ($left['items_count'] ?? 0);

            if ($countDiff !== 0) {
                return $countDiff;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $scopeRows;
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

    private function defaultProfileForSupplier(string $supplier): string
    {
        if ($supplier === 'metalmaster') {
            return app(MetalmasterSupplierProfile::class)->profileKey();
        }

        return app(VactoolSupplierProfile::class)->profileKey();
    }

    private function runType(string $supplier): string
    {
        return match ($supplier) {
            'metalmaster' => 'metalmaster_products',
            default => 'vactool_products',
        };
    }

    private function supplierLabel(string $supplier): string
    {
        return match ($supplier) {
            'metalmaster' => 'Metalmaster',
            default => 'Vactool',
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
