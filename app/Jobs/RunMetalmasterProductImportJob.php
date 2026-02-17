<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Support\Metalmaster\MetalmasterProductImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunMetalmasterProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public int $runId,
        public array $options,
        public bool $write,
    ) {}

    public function handle(MetalmasterProductImportService $service): void
    {
        $run = ImportRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        try {
            $result = $service->run(
                $this->options,
                null,
                function (array $progress) use ($run): void {
                    $this->saveProgress($run, $progress);
                },
            );

            $run->status = $this->resolveRunStatus($result);
            $run->totals = $this->buildFinalTotals($result);
            $run->finished_at = now();
            $run->save();

            if ($result['fatal_error'] !== null) {
                $run->issues()->create([
                    'row_index' => null,
                    'code' => 'buckets_error',
                    'severity' => 'error',
                    'message' => $result['fatal_error'],
                    'row_snapshot' => [
                        'buckets_file' => $this->options['buckets_file'] ?? null,
                        'bucket' => $this->options['bucket'] ?? null,
                    ],
                ]);
            }

            foreach ($result['url_errors'] as $urlError) {
                $run->issues()->create([
                    'row_index' => null,
                    'code' => 'parse_error',
                    'severity' => 'error',
                    'message' => $urlError['message'],
                    'row_snapshot' => [
                        'url' => $urlError['url'],
                    ],
                ]);
            }
        } catch (Throwable $exception) {
            $run->status = 'failed';
            $run->finished_at = now();
            $run->totals = $this->mergeMeta($run->totals, [
                'mode' => $this->write ? 'write' : 'dry-run',
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
     * @param  array<string, int|bool>  $progress
     */
    private function saveProgress(ImportRun $run, array $progress): void
    {
        $totals = is_array($run->totals) ? $run->totals : [];

        $totals['create'] = (int) ($progress['created'] ?? 0);
        $totals['update'] = (int) ($progress['updated'] ?? 0);
        $totals['same'] = (int) ($progress['skipped'] ?? 0);
        $totals['conflict'] = 0;
        $totals['error'] = (int) ($progress['errors'] ?? 0);
        $totals['scanned'] = (int) ($progress['processed'] ?? 0);

        $totals = $this->mergeMeta($totals, [
            'mode' => $this->write ? 'write' : 'dry-run',
            'found_urls' => (int) ($progress['found_urls'] ?? 0),
            'no_urls' => (bool) ($progress['no_urls'] ?? false),
            'is_running' => true,
        ]);

        $run->totals = $totals;
        $run->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildFinalTotals(array $result): array
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

        return $this->mergeMeta($totals, [
            'mode' => $this->write ? 'write' : 'dry-run',
            'found_urls' => (int) ($result['found_urls'] ?? 0),
            'no_urls' => (bool) ($result['no_urls'] ?? false),
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

        if ($this->write) {
            return ((int) ($result['processed'] ?? 0)) > 0 ? 'applied' : 'failed';
        }

        if (((int) ($result['processed'] ?? 0)) > 0 || ((bool) ($result['no_urls'] ?? false))) {
            return 'dry_run';
        }

        return 'failed';
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
