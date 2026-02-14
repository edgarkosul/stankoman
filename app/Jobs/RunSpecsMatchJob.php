<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Support\Products\SpecsMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunSpecsMatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, int>  $productIds
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public int $runId,
        public array $productIds,
        public array $options,
    ) {}

    public function handle(SpecsMatchService $service): void
    {
        $run = ImportRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        try {
            $result = $service->run($run, $this->productIds, $this->options);

            $run->status = $this->resolveRunStatus($result);
            $run->totals = $this->buildFinalTotals($result);
            $run->finished_at = now();
            $run->save();
        } catch (Throwable $exception) {
            $run->status = 'failed';
            $run->finished_at = now();
            $run->totals = $this->mergeMeta($run->totals, [
                'mode' => $this->isDryRun() ? 'dry-run' : 'write',
                'is_running' => false,
            ]);
            $run->save();

            $run->issues()->create([
                'row_index' => null,
                'code' => 'job_exception',
                'severity' => 'error',
                'message' => $exception->getMessage(),
                'row_snapshot' => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildFinalTotals(array $result): array
    {
        $totals = [
            'create' => (int) ($result['matched_pav'] ?? 0),
            'update' => (int) ($result['matched_pao'] ?? 0),
            'same' => (int) ($result['skipped'] ?? 0),
            'conflict' => 0,
            'error' => (int) ($result['issues'] ?? 0),
            'scanned' => (int) ($result['processed'] ?? 0),
        ];

        return $this->mergeMeta($totals, [
            'mode' => $this->isDryRun() ? 'dry-run' : 'write',
            'target_category_id' => (int) ($this->options['target_category_id'] ?? 0),
            'selected_products' => count($this->productIds),
            'only_empty_attributes' => (bool) ($this->options['only_empty_attributes'] ?? true),
            'overwrite_existing' => (bool) ($this->options['overwrite_existing'] ?? false),
            'auto_create_options' => (bool) ($this->options['auto_create_options'] ?? false),
            'detach_staging_after_success' => (bool) ($this->options['detach_staging_after_success'] ?? false),
            'attribute_links' => count((array) ($this->options['attribute_name_map'] ?? [])),
            'pav_matched' => (int) ($result['matched_pav'] ?? 0),
            'pao_matched' => (int) ($result['matched_pao'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'is_running' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function resolveRunStatus(array $result): string
    {
        if (($result['fatal_error'] ?? null) !== null) {
            return 'failed';
        }

        return $this->isDryRun() ? 'dry_run' : 'applied';
    }

    private function isDryRun(): bool
    {
        return (bool) ($this->options['dry_run'] ?? true);
    }

    /**
     * @param  array<string, mixed>|null  $totals
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function mergeMeta(?array $totals, array $meta): array
    {
        $totals = is_array($totals) ? $totals : [];
        $currentMeta = $totals['_meta'] ?? [];

        if (! is_array($currentMeta)) {
            $currentMeta = [];
        }

        $totals['_meta'] = array_merge($currentMeta, $meta);

        return $totals;
    }
}
