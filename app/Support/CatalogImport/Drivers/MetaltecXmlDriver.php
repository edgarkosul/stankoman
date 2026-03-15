<?php

namespace App\Support\CatalogImport\Drivers;

use App\Jobs\RunMetaltecProductImportJob;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Drivers\Contracts\SupplierImportDriver;
use App\Support\CatalogImport\Suppliers\Metaltec\MetaltecSupplierProfile;
use App\Support\Metaltec\MetaltecProductImportService;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use RuntimeException;
use Throwable;

final class MetaltecXmlDriver implements SupplierImportDriver
{
    public function __construct(
        private readonly MetaltecSupplierProfile $profile,
        private readonly MetaltecProductImportService $service,
    ) {}

    public function key(): string
    {
        return $this->profile->profileKey();
    }

    public function label(): string
    {
        return 'Metaltec XML';
    }

    public function availability(): DriverAvailability
    {
        return DriverAvailability::SupplierSpecific;
    }

    public function isAvailableForSupplier(?Supplier $supplier): bool
    {
        return trim((string) $supplier?->slug) === $this->profile->supplierKey();
    }

    public function profileKey(): string
    {
        return $this->profile->profileKey();
    }

    public function defaultSourceName(): string
    {
        return 'Основной XML';
    }

    public function supportsScope(): bool
    {
        return false;
    }

    public function supportsDeactivation(): bool
    {
        return false;
    }

    public function defaultSettings(): array
    {
        return [
            'source_url' => $this->profile->defaultSourceUrl(),
        ];
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('source_settings.source_url')
                ->label('URL XML-фида')
                ->required()
                ->url()
                ->live(onBlur: true)
                ->afterStateUpdated(function ($livewire): void {
                    if (method_exists($livewire, 'resetMetaltecFeedCategoriesIfSourceChanged')) {
                        $livewire->resetMetaltecFeedCategoriesIfSourceChanged();
                    }
                }),
        ];
    }

    public function importRuntimeSchema(): array
    {
        return [
            Actions::make([
                FormAction::make('load_metaltec_feed_categories')
                    ->label('Загрузить категории <Раздел>')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action('loadMetaltecFeedCategories'),
            ])->columnSpanFull(),
            Select::make('runtime.category_id')
                ->label('Категория из feed (опционально)')
                ->placeholder('Весь feed (без фильтра)')
                ->searchable()
                ->native(false)
                ->options(fn ($livewire): array => method_exists($livewire, 'metaltecFeedCategoryOptions')
                    ? $livewire->metaltecFeedCategoryOptions(limit: 100)
                    : [])
                ->getSearchResultsUsing(fn (string $search, $livewire): array => method_exists($livewire, 'metaltecFeedCategoryOptions')
                    ? $livewire->metaltecFeedCategoryOptions(search: $search, limit: 100)
                    : [])
                ->getOptionLabelUsing(fn ($value, $livewire): ?string => method_exists($livewire, 'metaltecFeedCategoryOptionLabel')
                    ? $livewire->metaltecFeedCategoryOptionLabel($value)
                    : null)
                ->helperText(fn ($livewire): string => method_exists($livewire, 'metaltecFeedCategoryHelperText')
                    ? $livewire->metaltecFeedCategoryHelperText()
                    : 'Оставьте пустым для импорта всего feed.'),
        ];
    }

    public function deactivationRuntimeSchema(): array
    {
        return [];
    }

    public function normalizeSettings(array $settings): array
    {
        return [
            'source_url' => $this->trimmedString($settings['source_url'] ?? null) ?? $this->profile->defaultSourceUrl(),
        ];
    }

    public function sourceLabel(array $settings): string
    {
        return (string) $this->normalizeSettings($settings)['source_url'];
    }

    public function importRunType(): string
    {
        return 'metaltec_products';
    }

    public function deactivationRunType(): ?string
    {
        return null;
    }

    public function buildImportOptions(SupplierImportSource $source, array $runtime): array
    {
        $settings = $this->normalizeSettings((array) ($source->settings ?? []));

        return [
            'supplier' => $this->profile->supplierKey(),
            'supplier_id' => $source->supplier_id,
            'supplier_name' => trim((string) $source->supplier?->name),
            'profile' => $source->profile_key ?: $this->profileKey(),
            'source' => $settings['source_url'],
            'source_label' => $settings['source_url'],
            'category_id' => $this->nullableInt($runtime['category_id'] ?? null),
            'category_name' => $this->trimmedString($runtime['category_name'] ?? null),
            'timeout' => 25,
            'delay_ms' => 0,
            'limit' => max(0, (int) ($runtime['limit'] ?? 0)),
            'show_samples' => max(0, (int) ($runtime['show_samples'] ?? 3)),
            'publish' => $this->toBool($runtime['publish'] ?? false),
            'download_images' => true,
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
        throw new RuntimeException('Driver does not support deactivation.');
    }

    public function dispatchImport(ImportRun $run, array $options, bool $write): void
    {
        RunMetaltecProductImportJob::dispatch($run->id, $options, $write)->afterCommit();
    }

    public function dispatchDeactivation(ImportRun $run, array $options, bool $write): void
    {
        throw new RuntimeException('Driver does not support deactivation.');
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

        try {
            $rawCategories = $this->service->listCategoryNodes([
                'source' => $normalized['source_url'],
                'timeout' => 25,
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        $categories = [];
        $categoryTree = [];
        $leafCategoryIds = [];

        foreach ($rawCategories as $rawCategory) {
            if (! is_array($rawCategory)) {
                continue;
            }

            $categoryId = $this->nullableInt($rawCategory['id'] ?? null);

            if ($categoryId === null) {
                continue;
            }

            $name = $this->trimmedString($rawCategory['name'] ?? null) ?? ('Категория #'.$categoryId);

            $categories[$categoryId] = $name;
            $categoryTree[$categoryId] = [
                'id' => $categoryId,
                'name' => $name,
                'parent_id' => null,
                'depth' => 0,
                'is_leaf' => true,
                'tree_name' => $name,
            ];
            $leafCategoryIds[$categoryId] = true;
        }

        return [
            'categories' => $categories,
            'category_tree' => $categoryTree,
            'leaf_category_ids' => $leafCategoryIds,
            'source_label' => $normalized['source_url'],
            'source_key' => $this->sourceKey($normalized),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function sourceKey(array $settings): string
    {
        $normalized = $this->normalizeSettings($settings);

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

            $categoryId = (int) ($category['id'] ?? 0);

            if ($categoryId <= 0) {
                continue;
            }

            $name = trim((string) ($category['name'] ?? ''));
            $label = $this->categoryLabel($categoryId, $name);

            if ($needle !== null && $needle !== '') {
                $idMatches = str_contains((string) $categoryId, $needle);
                $nameMatches = str_contains(mb_strtolower($label), $needle);

                if (! $idMatches && ! $nameMatches) {
                    continue;
                }
            }

            $options[(string) $categoryId] = $label;

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

            return is_string($categoryName)
                ? $this->categoryLabel($categoryId, trim($categoryName))
                : (string) $categoryId;
        }

        return $this->categoryLabel($categoryId, trim((string) ($category['name'] ?? '')));
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

    private function categoryLabel(int $categoryId, string $categoryName): string
    {
        if ($categoryName === '') {
            return '['.$categoryId.']';
        }

        return $categoryName;
    }
}
