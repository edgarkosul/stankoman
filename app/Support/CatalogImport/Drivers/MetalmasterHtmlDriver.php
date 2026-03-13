<?php

namespace App\Support\CatalogImport\Drivers;

use App\Jobs\RunMetalmasterProductImportJob;
use App\Models\ImportRun;
use App\Models\Supplier;
use App\Models\SupplierImportSource;
use App\Support\CatalogImport\Drivers\Contracts\SupplierImportDriver;
use App\Support\CatalogImport\Suppliers\Metalmaster\MetalmasterSupplierProfile;
use App\Support\Metalmaster\MetalmasterBucketCatalog;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;

final class MetalmasterHtmlDriver implements SupplierImportDriver
{
    public function __construct(
        private readonly MetalmasterSupplierProfile $profile,
        private readonly MetalmasterBucketCatalog $bucketCatalog,
    ) {}

    public function key(): string
    {
        return $this->profile->profileKey();
    }

    public function label(): string
    {
        return 'Metalmaster HTML';
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
        return 'Основной HTML';
    }

    public function supportsScope(): bool
    {
        return true;
    }

    public function supportsDeactivation(): bool
    {
        return false;
    }

    public function defaultSettings(): array
    {
        return [
            'timeout' => 25,
            'delay_ms' => 250,
            'download_images' => true,
        ];
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('source_settings.timeout')
                ->label('Таймаут запроса, сек')
                ->numeric()
                ->integer()
                ->minValue(1),
            TextInput::make('source_settings.delay_ms')
                ->label('Задержка между запросами, мс')
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
                FormAction::make('sync_metalmaster_buckets')
                    ->label('Синхронизировать buckets')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action('syncMetalmasterBuckets'),
            ])->columnSpanFull(),
            Select::make('runtime.scope')
                ->label('Bucket Metalmaster')
                ->placeholder('Все buckets')
                ->searchable()
                ->native(false)
                ->options(fn (): array => $this->bucketCatalog->options(limit: 100))
                ->getSearchResultsUsing(fn (string $search): array => $this->bucketCatalog->options(search: $search, limit: 100))
                ->getOptionLabelUsing(fn ($value): ?string => $this->bucketCatalog->label(is_scalar($value) ? (string) $value : null))
                ->helperText('Выберите bucket из актуального списка. Если список пуст, сначала синхронизируйте buckets.'),
        ];
    }

    public function deactivationRuntimeSchema(): array
    {
        return [];
    }

    public function normalizeSettings(array $settings): array
    {
        return [
            'buckets_file' => $this->profile->defaultBucketsFile(),
            'timeout' => max(1, (int) ($settings['timeout'] ?? 25)),
            'delay_ms' => max(0, (int) ($settings['delay_ms'] ?? 250)),
            'download_images' => $this->toBool($settings['download_images'] ?? true),
        ];
    }

    public function sourceLabel(array $settings): string
    {
        $normalized = $this->normalizeSettings($settings);

        return (string) $normalized['buckets_file'];
    }

    public function importRunType(): string
    {
        return 'metalmaster_products';
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
            'buckets_file' => $settings['buckets_file'],
            'bucket' => $this->trimmedString($runtime['scope'] ?? null) ?? '',
            'timeout' => $settings['timeout'],
            'delay_ms' => $settings['delay_ms'],
            'limit' => max(0, (int) ($runtime['limit'] ?? 0)),
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
        throw new \RuntimeException('Driver does not support deactivation.');
    }

    public function dispatchImport(ImportRun $run, array $options, bool $write): void
    {
        RunMetalmasterProductImportJob::dispatch($run->id, $options, $write)->afterCommit();
    }

    public function dispatchDeactivation(ImportRun $run, array $options, bool $write): void
    {
        throw new \RuntimeException('Driver does not support deactivation.');
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
