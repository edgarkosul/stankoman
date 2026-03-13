<?php

namespace App\Support\CatalogImport\Drivers;

use App\Jobs\RunYandexMarketFeedDeactivationJob;
use App\Jobs\RunYandexMarketFeedImportJob;
use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Drivers\Contracts\SupplierImportDriver;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use App\Support\CatalogImport\Yml\YandexMarketFeedProfile;
use App\Support\CatalogImport\Yml\YandexMarketFeedSourceHistoryService;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

final class YandexMarketFeedDriver implements SupplierImportDriver
{
    public function __construct(
        private readonly YandexMarketFeedProfile $profile,
        private readonly YandexMarketFeedImportService $service,
        private readonly YandexMarketFeedSourceHistoryService $history,
    ) {}

    public function key(): string
    {
        return $this->profile->supplierKey();
    }

    public function label(): string
    {
        return $this->profile->profileName();
    }

    public function availability(): DriverAvailability
    {
        return DriverAvailability::Universal;
    }

    public function isAvailableForSupplier(?Supplier $supplier): bool
    {
        return true;
    }

    public function profileKey(): string
    {
        return $this->profile->profileKey();
    }

    public function defaultSourceName(): string
    {
        return 'Основной feed';
    }

    public function supportsScope(): bool
    {
        return true;
    }

    public function supportsDeactivation(): bool
    {
        return true;
    }

    public function defaultSettings(): array
    {
        return [
            'source_mode' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL,
            'source_url' => '',
            'source_history_id' => null,
            'timeout' => 25,
            'delay_ms' => 0,
            'download_images' => true,
        ];
    }

    public function settingsSchema(): array
    {
        return [
            Select::make('source_settings.source_mode')
                ->label('Источник feed')
                ->options([
                    YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL => 'URL',
                    'history' => 'Из истории успешных',
                ])
                ->default(YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL)
                ->native(false)
                ->live()
                ->afterStateUpdated(function ($livewire): void {
                    if (method_exists($livewire, 'resetYandexFeedCategoriesIfSourceChanged')) {
                        $livewire->resetYandexFeedCategoriesIfSourceChanged();
                    }
                }),
            TextInput::make('source_settings.source_url')
                ->label('URL фида')
                ->live(onBlur: true)
                ->visible(
                    fn (Get $get): bool => (string) $get('source_settings.source_mode') === YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL,
                )
                ->required(
                    fn (Get $get): bool => (string) $get('source_settings.source_mode') === YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL,
                )
                ->url()
                ->afterStateUpdated(function ($livewire): void {
                    if (method_exists($livewire, 'resetYandexFeedCategoriesIfSourceChanged')) {
                        $livewire->resetYandexFeedCategoriesIfSourceChanged();
                    }
                }),
            Select::make('source_settings.source_history_id')
                ->label('Источник из истории')
                ->visible(fn (Get $get): bool => (string) $get('source_settings.source_mode') === 'history')
                ->required(fn (Get $get): bool => (string) $get('source_settings.source_mode') === 'history')
                ->searchable()
                ->native(false)
                ->live()
                ->options(fn (): array => $this->history->historyOptions(limit: 100))
                ->getSearchResultsUsing(fn (string $search): array => $this->history->historyOptions(search: $search, limit: 100))
                ->getOptionLabelUsing(fn ($value): ?string => $this->history->historyOptionLabel($value))
                ->afterStateUpdated(function ($livewire): void {
                    if (method_exists($livewire, 'resetYandexFeedCategoriesIfSourceChanged')) {
                        $livewire->resetYandexFeedCategoriesIfSourceChanged();
                    }
                }),
            TextInput::make('source_settings.timeout')
                ->label('Таймаут запроса, сек')
                ->numeric()
                ->integer()
                ->minValue(1),
            TextInput::make('source_settings.delay_ms')
                ->label('Задержка между offer, мс')
                ->numeric()
                ->integer()
                ->minValue(0),
            Toggle::make('source_settings.download_images')
                ->label('Скачивать изображения'),
        ];
    }

    public function importRuntimeSchema(): array
    {
        return [
            Actions::make([
                FormAction::make('load_yandex_feed_categories')
                    ->label('Загрузить категории <category>')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action('loadYandexFeedCategories'),
            ])->columnSpanFull(),
            Select::make('runtime.category_id')
                ->label('Категория из feed (опционально)')
                ->placeholder('Весь feed (без фильтра)')
                ->searchable()
                ->native(false)
                ->options(fn ($livewire): array => method_exists($livewire, 'yandexFeedCategoryOptions')
                    ? $livewire->yandexFeedCategoryOptions(limit: 100)
                    : [])
                ->getSearchResultsUsing(fn (string $search, $livewire): array => method_exists($livewire, 'yandexFeedCategoryOptions')
                    ? $livewire->yandexFeedCategoryOptions(search: $search, limit: 100)
                    : [])
                ->getOptionLabelUsing(fn ($value, $livewire): ?string => method_exists($livewire, 'yandexFeedCategoryOptionLabel')
                    ? $livewire->yandexFeedCategoryOptionLabel($value)
                    : null)
                ->helperText(fn ($livewire): string => method_exists($livewire, 'yandexFeedCategoryHelperText')
                    ? $livewire->yandexFeedCategoryHelperText()
                    : 'Оставьте пустым для импорта всего feed.'),
        ];
    }

    public function deactivationRuntimeSchema(): array
    {
        return [
            Select::make('deactivation.site_category_id')
                ->label('Категория сайта для деактивации')
                ->required()
                ->searchable()
                ->native(false)
                ->options(fn (): array => $this->siteCategoryOptions(limit: 100))
                ->getSearchResultsUsing(fn (string $search): array => $this->siteCategoryOptions(search: $search, limit: 100))
                ->getOptionLabelUsing(fn ($value): ?string => $this->siteCategoryOptionLabel($value)),
            TextInput::make('deactivation.show_samples')
                ->label('Кандидаты в превью dry-run')
                ->numeric()
                ->integer()
                ->minValue(0),
        ];
    }

    public function normalizeSettings(array $settings): array
    {
        $mode = (string) ($settings['source_mode'] ?? YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL);
        $mode = $mode === 'history' ? 'history' : YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL;

        return [
            'source_mode' => $mode,
            'source_url' => $this->trimmedString($settings['source_url'] ?? null) ?? '',
            'source_history_id' => $this->nullableInt($settings['source_history_id'] ?? null),
            'timeout' => max(1, (int) ($settings['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) ($settings['delay_ms'] ?? 0)),
            'download_images' => $this->toBool($settings['download_images'] ?? true),
        ];
    }

    public function sourceLabel(array $settings): string
    {
        $normalized = $this->normalizeSettings($settings);

        if ($normalized['source_mode'] === 'history') {
            return $this->history->historyOptionLabel($normalized['source_history_id']) ?? 'Источник из истории';
        }

        return (string) $normalized['source_url'];
    }

    public function importRunType(): string
    {
        return 'yandex_market_feed_products';
    }

    public function deactivationRunType(): ?string
    {
        return 'yandex_market_feed_deactivation';
    }

    public function buildImportOptions(SupplierImportSource $source, array $runtime): array
    {
        $settings = $this->normalizeSettings((array) ($source->settings ?? []));
        $resolvedSource = $this->resolveAndValidateSource($settings);

        return [
            'supplier' => $this->profile->supplierKey(),
            'supplier_id' => $source->supplier_id,
            'supplier_name' => trim((string) $source->supplier?->name),
            'profile' => $source->profile_key ?: $this->profileKey(),
            'source' => $resolvedSource['source'],
            'source_type' => $resolvedSource['source_type'],
            'source_id' => $resolvedSource['source_id'],
            'source_label' => $resolvedSource['source_label'],
            'category_id' => $this->nullableInt($runtime['category_id'] ?? null),
            'limit' => max(0, (int) ($runtime['limit'] ?? 0)),
            'timeout' => $settings['timeout'],
            'delay_ms' => $settings['delay_ms'],
            'show_samples' => max(0, (int) ($runtime['show_samples'] ?? 3)),
            'publish' => $this->toBool($runtime['publish'] ?? false),
            'download_images' => $settings['download_images'],
            'force_media_recheck' => $this->toBool($runtime['force_media_recheck'] ?? false),
            'skip_existing' => $this->toBool($runtime['skip_existing'] ?? false),
            'mode' => 'partial_import',
            'finalize_missing' => false,
            'create_missing' => $this->toBool($runtime['create_missing'] ?? true),
            'update_existing' => $this->toBool($runtime['update_existing'] ?? true),
            'error_threshold_count' => $this->nullableInt($runtime['error_threshold_count'] ?? null),
            'error_threshold_percent' => $this->nullableFloat($runtime['error_threshold_percent'] ?? null),
        ];
    }

    public function buildDeactivationOptions(SupplierImportSource $source, array $runtime): array
    {
        $settings = $this->normalizeSettings((array) ($source->settings ?? []));
        $resolvedSource = $this->resolveAndValidateSource($settings);
        $siteCategoryId = $this->nullableInt($runtime['site_category_id'] ?? null);

        if ($siteCategoryId === null) {
            throw new RuntimeException('Выберите категорию сайта для деактивации.');
        }

        return [
            'supplier' => $this->profile->supplierKey(),
            'supplier_id' => $source->supplier_id,
            'supplier_name' => trim((string) $source->supplier?->name),
            'profile' => $source->profile_key ?: $this->profileKey(),
            'source' => $resolvedSource['source'],
            'source_type' => $resolvedSource['source_type'],
            'source_id' => $resolvedSource['source_id'],
            'source_label' => $resolvedSource['source_label'],
            'site_category_id' => $siteCategoryId,
            'site_category_name' => trim((string) Category::query()->whereKey($siteCategoryId)->value('name')),
            'timeout' => $settings['timeout'],
            'show_samples' => max(0, (int) ($runtime['show_samples'] ?? 20)),
        ];
    }

    public function dispatchImport(ImportRun $run, array $options, bool $write): void
    {
        $sourceId = $this->nullableInt($options['source_id'] ?? null);

        if ($sourceId !== null) {
            $this->history->markUsedById($sourceId, $run->id);
        }

        RunYandexMarketFeedImportJob::dispatch($run->id, $options, $write)->afterCommit();
    }

    public function dispatchDeactivation(ImportRun $run, array $options, bool $write): void
    {
        $sourceId = $this->nullableInt($options['source_id'] ?? null);

        if ($sourceId !== null) {
            $this->history->markUsedById($sourceId, $run->id);
        }

        RunYandexMarketFeedDeactivationJob::dispatch($run->id, $options, $write)->afterCommit();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     categories: array<int, string>,
     *     category_tree: array<int, array{id: int, name: string, parent_id: int|null, depth: int, is_leaf: bool, tree_name: string}>,
     *     leaf_category_ids: array<int, true>,
     *     source_label: string,
     *     source_key: string
     * }
     */
    public function loadFeedCategories(array $settings): array
    {
        $normalized = $this->normalizeSettings($settings);
        $resolved = $this->resolveSource($normalized);

        try {
            $rawCategories = $this->service->listCategoryNodes([
                'source' => $resolved['source'],
                'timeout' => $normalized['timeout'],
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        if ($resolved['source_type'] === YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL) {
            $record = $this->history->rememberValidUrl(
                url: $resolved['source'],
                userId: Auth::id(),
            );

            $resolved['source_id'] = (int) $record->id;
            $resolved['source_label'] = (string) ($record->source_url ?: $resolved['source']);
        }

        $categories = [];
        $normalizedCategories = [];

        foreach ($rawCategories as $rawCategory) {
            if (! is_array($rawCategory)) {
                continue;
            }

            $categoryId = $this->nullableInt($rawCategory['id'] ?? null);

            if ($categoryId === null) {
                continue;
            }

            $name = trim((string) ($rawCategory['name'] ?? ''));
            $parentId = $this->nullableInt($rawCategory['parent_id'] ?? $rawCategory['parentId'] ?? null);

            if ($parentId === $categoryId) {
                $parentId = null;
            }

            $label = $name !== '' ? $name : ('Категория #'.$categoryId);

            $categories[$categoryId] = $label;
            $normalizedCategories[$categoryId] = [
                'id' => $categoryId,
                'name' => $label,
                'parent_id' => $parentId,
            ];
        }

        [$categoryTree, $leafCategoryIds] = $this->buildCategoryTree($normalizedCategories);

        return [
            'categories' => $categories,
            'category_tree' => $categoryTree,
            'leaf_category_ids' => $leafCategoryIds,
            'source_label' => $resolved['source_label'],
            'source_key' => $this->sourceKey($normalized),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function sourceKey(array $settings): string
    {
        $normalized = $this->normalizeSettings($settings);

        if (($normalized['source_mode'] ?? null) === 'history') {
            return 'history|'.(string) ($this->nullableInt($normalized['source_history_id'] ?? null) ?? '');
        }

        return 'url|'.trim((string) ($normalized['source_url'] ?? ''));
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_id: int|null, depth: int, is_leaf: bool, tree_name: string}>  $categoryTree
     * @return array<string, string>
     */
    public function categoryOptions(array $categoryTree, ?string $search = null, int $limit = 100): array
    {
        if ($categoryTree === []) {
            return [];
        }

        $options = [];
        $needle = $search !== null ? mb_strtolower(trim($search)) : null;

        foreach ($categoryTree as $category) {
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

    /**
     * @param  array<int, array{id: int, name: string, parent_id: int|null, depth: int, is_leaf: bool, tree_name: string}>  $categoryTree
     * @param  array<int, string>  $categories
     */
    public function categoryOptionLabel(array $categoryTree, array $categories, mixed $value): ?string
    {
        $categoryId = $this->nullableInt($value);

        if ($categoryId === null) {
            return null;
        }

        $category = $categoryTree[$categoryId] ?? null;

        if (! is_array($category)) {
            $categoryName = $categories[$categoryId] ?? null;

            if (! is_string($categoryName)) {
                return (string) $categoryId;
            }

            return $this->categoryLabel($categoryId, trim($categoryName));
        }

        $categoryName = trim((string) ($category['name'] ?? ''));
        $depth = max(0, (int) ($category['depth'] ?? 0));

        return $this->categoryLabel($categoryId, $categoryName, $depth);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{source:string,source_type:string,source_id:int|null,source_label:string}
     */
    private function resolveAndValidateSource(array $settings): array
    {
        $resolved = $this->resolveSource($settings);

        try {
            $this->service->listCategoryNodes([
                'source' => $resolved['source'],
                'timeout' => $settings['timeout'],
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        if ($resolved['source_type'] === YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL) {
            $record = $this->history->rememberValidUrl(
                url: $resolved['source'],
                userId: Auth::id(),
            );

            $resolved['source_id'] = (int) $record->id;
            $resolved['source_label'] = (string) ($record->source_url ?: $resolved['source']);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{source:string,source_type:string,source_id:int|null,source_label:string}
     */
    private function resolveSource(array $settings): array
    {
        if (($settings['source_mode'] ?? null) === 'history') {
            $historyId = $this->nullableInt($settings['source_history_id'] ?? null);

            if ($historyId === null) {
                throw new RuntimeException('Выберите источник из истории.');
            }

            $resolved = $this->history->resolveFromHistoryId($historyId);

            if (! is_array($resolved)) {
                throw new RuntimeException('Источник из истории недоступен. Выберите другой.');
            }

            return [
                'source' => (string) $resolved['source'],
                'source_type' => (string) $resolved['source_type'],
                'source_id' => $this->nullableInt($resolved['source_id'] ?? null),
                'source_label' => (string) ($resolved['source_label'] ?? 'Источник из истории'),
            ];
        }

        $sourceUrl = $this->trimmedString($settings['source_url'] ?? null);

        if ($sourceUrl === null) {
            throw new RuntimeException('Укажите URL feed для выбранного варианта импорта.');
        }

        return [
            'source' => $sourceUrl,
            'source_type' => YandexMarketFeedSourceHistoryService::SOURCE_TYPE_URL,
            'source_id' => null,
            'source_label' => $sourceUrl,
        ];
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
            $parentId = $this->nullableInt($category['parent_id'] ?? null);

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
            if (isset($visited[$categoryId]) || isset($path[$categoryId])) {
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

        foreach ($childrenByParent[0] ?? [] as $rootCategoryId) {
            $walk($rootCategoryId, 0);
        }

        foreach (array_keys($normalized) as $categoryId) {
            if (! isset($tree[$categoryId])) {
                $walk($categoryId, 0);
            }
        }

        return [$tree, $leafCategoryIds];
    }

    /**
     * @return array<string, string>
     */
    private function siteCategoryOptions(?string $search = null, int $limit = 100): array
    {
        $query = Category::query()
            ->withoutStaging()
            ->orderBy('name');

        $needle = trim((string) $search);

        if ($needle !== '') {
            $query->where('name', 'like', "%{$needle}%");
        }

        return $query
            ->limit($limit)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();
    }

    private function siteCategoryOptionLabel(mixed $value): ?string
    {
        $categoryId = $this->nullableInt($value);

        if ($categoryId === null) {
            return null;
        }

        $label = Category::query()->whereKey($categoryId)->value('name');

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function nullableFloat(mixed $value): ?float
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
