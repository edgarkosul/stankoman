<?php

namespace App\Support\CatalogImport\Runs;

use App\Models\ImportRun;
use App\Support\CatalogImport\Enums\ImportRunStatus;

final class ImportRunOrchestrator
{
    /**
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $meta
     */
    public function start(
        string $type,
        array $columns,
        string $mode,
        ?string $sourceFilename = null,
        ?int $userId = null,
        array $meta = [],
    ): ImportRun {
        return ImportRun::query()->create([
            'type' => $type,
            'status' => ImportRunStatus::Pending->value,
            'columns' => $columns,
            'totals' => $this->initialTotals($mode, $meta),
            'source_filename' => $sourceFilename,
            'stored_path' => null,
            'user_id' => $userId,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function markFailed(ImportRun $run, string $mode, array $meta = []): void
    {
        $run->status = ImportRunStatus::Failed->value;
        $run->finished_at = $run->finished_at ?? now();
        $run->totals = $this->mergeMeta($run->totals, array_merge([
            'mode' => $mode,
            'is_running' => false,
        ], $meta));
        $run->save();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function markCancelled(ImportRun $run, string $mode, array $meta = []): void
    {
        $cancelledAt = data_get($run->totals, '_meta.cancelled_at');

        if (! is_string($cancelledAt) || $cancelledAt === '') {
            $cancelledAt = now()->toIso8601String();
        }

        $run->status = ImportRunStatus::Cancelled->value;
        $run->finished_at = $run->finished_at ?? now();
        $run->totals = $this->mergeMeta($run->totals, array_merge([
            'mode' => $mode,
            'is_running' => false,
            'cancelled_by_user' => true,
            'cancelled_at' => $cancelledAt,
        ], $meta));
        $run->save();
    }

    /**
     * @param  array<string, int|bool>  $progress
     * @param  array<string, mixed>  $meta
     */
    public function saveProgress(ImportRun $run, array $progress, string $mode, array $meta = []): void
    {
        $totals = is_array($run->totals) ? $run->totals : [];

        $totals['create'] = (int) ($progress['created'] ?? 0);
        $totals['update'] = (int) ($progress['updated'] ?? 0);
        $totals['same'] = (int) ($progress['skipped'] ?? 0);
        $totals['conflict'] = 0;
        $totals['error'] = (int) ($progress['errors'] ?? 0);
        $totals['scanned'] = (int) ($progress['processed'] ?? 0);

        $totals = $this->mergeMeta($totals, array_merge([
            'mode' => $mode,
            'found_urls' => (int) ($progress['found_urls'] ?? 0),
            'images_downloaded' => (int) ($progress['images_downloaded'] ?? 0),
            'image_download_failed' => (int) ($progress['image_download_failed'] ?? 0),
            'derivatives_queued' => (int) ($progress['derivatives_queued'] ?? 0),
            'no_urls' => (bool) ($progress['no_urls'] ?? false),
            'is_running' => true,
        ], $meta));

        $run->totals = $totals;
        $run->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $meta
     */
    public function completeFromResult(ImportRun $run, array $result, bool $write, array $meta = []): void
    {
        $mode = $write ? 'write' : 'dry-run';

        $run->status = $this->resolveLegacyStatus($result, $write)->value;
        $run->totals = $this->buildFinalTotals($result, $mode, $meta);
        $run->finished_at = now();
        $run->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function resolveLegacyStatus(array $result, bool $write): ImportRunStatus
    {
        if (($result['fatal_error'] ?? null) !== null) {
            return ImportRunStatus::Failed;
        }

        if ($write) {
            return ((int) ($result['processed'] ?? 0)) > 0
                ? ImportRunStatus::Applied
                : ImportRunStatus::Failed;
        }

        if (((int) ($result['processed'] ?? 0)) > 0 || ((bool) ($result['no_urls'] ?? false))) {
            return ImportRunStatus::DryRun;
        }

        return ImportRunStatus::Failed;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function buildFinalTotals(array $result, string $mode, array $meta = []): array
    {
        $totals = [
            'create' => (int) ($result['created'] ?? 0),
            'update' => (int) ($result['updated'] ?? 0),
            'same' => (int) ($result['skipped'] ?? 0),
            'conflict' => 0,
            'error' => (int) ($result['errors'] ?? 0),
            'scanned' => (int) ($result['processed'] ?? 0),
            '_samples' => $result['samples'] ?? [],
        ];

        return $this->mergeMeta($totals, array_merge([
            'mode' => $mode,
            'found_urls' => (int) ($result['found_urls'] ?? 0),
            'images_downloaded' => (int) ($result['images_downloaded'] ?? 0),
            'image_download_failed' => (int) ($result['image_download_failed'] ?? 0),
            'derivatives_queued' => (int) ($result['derivatives_queued'] ?? 0),
            'no_urls' => (bool) ($result['no_urls'] ?? false),
            'is_running' => false,
        ], $meta));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function initialTotals(string $mode, array $meta = []): array
    {
        return $this->mergeMeta([
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'conflict' => 0,
            'error' => 0,
            'scanned' => 0,
            '_samples' => [],
        ], array_merge([
            'mode' => $mode,
            'found_urls' => 0,
            'images_downloaded' => 0,
            'image_download_failed' => 0,
            'derivatives_queued' => 0,
            'no_urls' => false,
            'is_running' => true,
        ], $meta));
    }

    /**
     * @param  array<string, mixed>|null  $totals
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function mergeMeta(?array $totals, array $meta): array
    {
        $totals = is_array($totals) ? $totals : [];
        $currentMeta = $totals['_meta'] ?? [];

        if (! is_array($currentMeta)) {
            $currentMeta = [];
        }

        $totals['_meta'] = array_merge($currentMeta, $meta);

        return $totals;
    }

    public function isCancelled(ImportRun $run): bool
    {
        return (string) ImportRun::query()->whereKey($run->id)->value('status') === ImportRunStatus::Cancelled->value;
    }

    public function resolveMode(ImportRun $run, string $default = 'dry-run'): string
    {
        $mode = data_get($run->totals, '_meta.mode');

        if (is_string($mode) && $mode !== '') {
            return $mode;
        }

        return (bool) data_get($run->columns, 'write', false) ? 'write' : $default;
    }

    /**
     * @param  array<string, int|bool>  $progress
     * @param  array<string, mixed>  $options
     * @return array{metric:string,threshold:int|float,actual:int|float}|null
     */
    public function thresholdExceeded(array $progress, array $options): ?array
    {
        $errors = (int) ($progress['errors'] ?? 0);
        $processed = (int) ($progress['processed'] ?? 0);

        $countThreshold = $this->positiveIntOrNull($options['error_threshold_count'] ?? null);

        if ($countThreshold !== null && $errors >= $countThreshold) {
            return [
                'metric' => 'count',
                'threshold' => $countThreshold,
                'actual' => $errors,
            ];
        }

        $percentThreshold = $this->positiveFloatOrNull($options['error_threshold_percent'] ?? null);

        if ($percentThreshold !== null && $processed > 0) {
            $errorPercent = ($errors / $processed) * 100;

            if ($errorPercent >= $percentThreshold) {
                return [
                    'metric' => 'percent',
                    'threshold' => $percentThreshold,
                    'actual' => $errorPercent,
                ];
            }
        }

        return null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $parsed = (int) $value;

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function positiveFloatOrNull(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            $parsed = (float) $value;

            return $parsed > 0 ? $parsed : null;
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));

            if ($normalized !== '' && is_numeric($normalized)) {
                $parsed = (float) $normalized;

                return $parsed > 0 ? $parsed : null;
            }
        }

        return null;
    }
}
