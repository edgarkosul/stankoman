<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Jobs\RunYandexMarketFeedImportJob;
use App\Models\ImportRun;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
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
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Throwable;
use UnitEnum;

class YandexMarketFeedImport extends Page implements HasForms
{
    use InteractsWithForms;

    private const DISPLAY_TIMEZONE = 'Europe/Moscow';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Экспорт/Импорт';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Импорт Yandex Feed';

    protected static ?string $title = 'Импорт товаров из Yandex Market Feed';

    protected string $view = 'filament.pages.yandex-market-feed-import';

    /** @var array{
     *     source: string,
     *     category_id: int|null,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int,
     *     mode: string,
     *     finalize_missing: bool,
     *     create_missing: bool,
     *     update_existing: bool
     * }|null
     */
    public ?array $data = null;

    /** @var array<int, string> */
    public array $parsedCategories = [];

    public ?string $categoriesLoadedAt = null;

    public ?string $categoriesLoadedSource = null;

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
                Section::make('Источник Yandex Market Feed')
                    ->description('Можно запустить импорт всего фида или в два этапа: сначала загрузить категории, затем выбрать одну категорию для прогона.')
                    ->schema([
                        TextInput::make('source')
                            ->label('Источник фида (URL или путь к файлу)')
                            ->required(),
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
                                'Сначала нажмите "Загрузить категории <category>", затем выберите нужную категорию. Оставьте пустым для импорта всего фида.',
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
                        Toggle::make('finalize_missing')
                            ->label('Finalize missing (только full_sync)')
                            ->default(false),
                        Toggle::make('create_missing')
                            ->label('Создавать новые товары')
                            ->default(true),
                        Toggle::make('update_existing')
                            ->label('Обновлять существующие товары')
                            ->default(true),
                        Toggle::make('skip_existing')
                            ->label('Пропускать уже существующие товары (prefilter)'),
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

    public function updatedDataSource(mixed $value): void
    {
        $source = is_string($value) ? trim($value) : '';

        if ($source === $this->categoriesLoadedSource) {
            return;
        }

        $this->parsedCategories = [];
        $this->categoriesLoadedAt = null;
        $this->categoriesLoadedSource = null;

        if (is_array($this->data)) {
            $this->data['category_id'] = null;
        }
    }

    public function loadFeedCategories(): void
    {
        $source = trim((string) ($this->data['source'] ?? ''));

        if ($source === '') {
            Notification::make()
                ->title('Не указан источник фида')
                ->body('Заполните поле "Источник фида" перед загрузкой категорий.')
                ->warning()
                ->send();

            return;
        }

        try {
            $categories = app(YandexMarketFeedImportService::class)->listCategories([
                'source' => $source,
                'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            ]);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Не удалось загрузить категории')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->parsedCategories = [];

        foreach ($categories as $categoryId => $categoryName) {
            if (! is_int($categoryId) || $categoryId <= 0) {
                continue;
            }

            $name = trim((string) $categoryName);

            $this->parsedCategories[$categoryId] = $name !== '' ? $name : ('Категория #'.$categoryId);
        }

        $selectedCategoryId = $this->normalizeNullableInt($this->data['category_id'] ?? null);

        if ($selectedCategoryId !== null && ! isset($this->parsedCategories[$selectedCategoryId])) {
            $this->data['category_id'] = null;
        }

        $this->categoriesLoadedAt = now()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s');
        $this->categoriesLoadedSource = $source;

        Notification::make()
            ->title('Категории загружены')
            ->body('Найдено категорий: '.count($this->parsedCategories).'.')
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

        $options = $this->buildOptions($write);
        $mode = $write ? 'write' : 'dry-run';
        $runs = app(ImportRunOrchestrator::class);
        $run = $runs->start(
            type: 'yandex_market_feed_products',
            columns: $options,
            mode: $mode,
            sourceFilename: $options['source'],
            userId: Auth::id(),
        );

        RunYandexMarketFeedImportJob::dispatch($run->id, $options, $write);

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
     *     category_id: int|null,
     *     limit: int,
     *     timeout: int,
     *     delay_ms: int,
     *     write: bool,
     *     publish: bool,
     *     download_images: bool,
     *     skip_existing: bool,
     *     show_samples: int,
     *     mode: string,
     *     finalize_missing: bool,
     *     create_missing: bool,
     *     update_existing: bool
     * }
     */
    private function buildOptions(bool $write): array
    {
        $mode = (string) ($this->data['mode'] ?? 'partial_import');

        if (! in_array($mode, ['partial_import', 'full_sync_authoritative'], true)) {
            $mode = 'partial_import';
        }

        $source = trim((string) ($this->data['source'] ?? ''));

        return [
            'source' => $source !== '' ? $source : $this->defaultSource(),
            'category_id' => $this->normalizeNullableInt($this->data['category_id'] ?? null),
            'limit' => max(0, (int) ($this->data['limit'] ?? 0)),
            'timeout' => max(1, (int) ($this->data['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) ($this->data['delay_ms'] ?? 0)),
            'write' => $write,
            'publish' => (bool) ($this->data['publish'] ?? false),
            'download_images' => (bool) ($this->data['download_images'] ?? true),
            'skip_existing' => (bool) ($this->data['skip_existing'] ?? false),
            'show_samples' => max(0, (int) ($this->data['show_samples'] ?? 3)),
            'mode' => $mode,
            'finalize_missing' => (bool) ($this->data['finalize_missing'] ?? ($mode === 'full_sync_authoritative')),
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
            'source' => (string) ($columns['source'] ?? ''),
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
            'source' => $this->defaultSource(),
            'category_id' => null,
            'limit' => 0,
            'timeout' => 25,
            'delay_ms' => 0,
            'publish' => false,
            'download_images' => true,
            'skip_existing' => false,
            'show_samples' => 3,
            'mode' => 'partial_import',
            'finalize_missing' => false,
            'create_missing' => true,
            'update_existing' => true,
        ];
    }

    private function defaultSource(): string
    {
        return storage_path('app/parser/yandex-market-feed.xml');
    }

    /**
     * @return array<string, string>
     */
    private function categoryOptions(?string $search = null, int $limit = 100): array
    {
        if ($this->parsedCategories === []) {
            return [];
        }

        $options = [];
        $needle = $search !== null ? mb_strtolower(trim($search)) : null;

        foreach ($this->parsedCategories as $categoryId => $categoryName) {
            $id = (int) $categoryId;

            if ($id <= 0) {
                continue;
            }

            $name = trim((string) $categoryName);
            $label = $this->categoryLabel($id, $name);

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

        $categoryName = $this->parsedCategories[$categoryId] ?? null;

        if (! is_string($categoryName)) {
            return (string) $categoryId;
        }

        return $this->categoryLabel($categoryId, trim($categoryName));
    }

    private function categoryLabel(int $categoryId, string $categoryName): string
    {
        if ($categoryName === '') {
            return '['.$categoryId.']';
        }

        return '['.$categoryId.'] '.$categoryName;
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
