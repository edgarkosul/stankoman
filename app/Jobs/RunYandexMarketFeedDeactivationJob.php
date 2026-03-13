<?php

namespace App\Jobs;

use App\Exceptions\ImportRunCancelledException;
use App\Models\ImportRun;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Yml\YandexMarketFeedDeactivationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunYandexMarketFeedDeactivationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public int $runId,
        public array $options,
        public bool $write,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('catalog_import_yandex_market_feed_deactivate'))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(YandexMarketFeedDeactivationService $service, ImportRunOrchestrator $runs): void
    {
        $run = ImportRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        if ($runs->isCancelled($run)) {
            $runs->markCancelled($run, $this->mode());

            return;
        }

        try {
            $result = $service->run(
                array_merge($this->options, [
                    'run_id' => $this->runId,
                    'write' => $this->write,
                ]),
                null,
                function (array $progress) use ($run, $runs): void {
                    $runs->saveProgress(
                        run: $run,
                        progress: [
                            'found_urls' => (int) ($progress['found_urls'] ?? 0),
                            'processed' => (int) ($progress['processed'] ?? 0),
                            'errors' => (int) ($progress['errors'] ?? 0),
                            'created' => 0,
                            'updated' => (int) ($progress['deactivated'] ?? 0),
                            'skipped' => (int) ($progress['candidates'] ?? 0),
                            'images_downloaded' => 0,
                            'image_download_failed' => 0,
                            'derivatives_queued' => 0,
                            'no_urls' => (bool) ($progress['no_urls'] ?? false),
                        ],
                        mode: $this->mode(),
                        meta: [
                            'candidates' => (int) ($progress['candidates'] ?? 0),
                            'deactivated' => (int) ($progress['deactivated'] ?? 0),
                        ],
                    );

                    if ($runs->isCancelled($run)) {
                        throw new ImportRunCancelledException('Запуск деактивации остановлен пользователем.');
                    }
                },
            );

            $run->refresh();

            if ($runs->isCancelled($run)) {
                $runs->markCancelled($run, $this->mode());

                return;
            }

            $runs->completeFromResult(
                run: $run,
                result: [
                    'found_urls' => (int) ($result['found_urls'] ?? 0),
                    'processed' => (int) ($result['processed'] ?? 0),
                    'errors' => (int) ($result['errors'] ?? 0),
                    'created' => 0,
                    'updated' => (int) ($result['deactivated'] ?? 0),
                    'skipped' => (int) ($result['candidates'] ?? 0),
                    'samples' => $result['samples'] ?? [],
                    'fatal_error' => $result['fatal_error'] ?? null,
                    'no_urls' => (bool) ($result['no_urls'] ?? false),
                ],
                write: $this->write,
                meta: [
                    'candidates' => (int) ($result['candidates'] ?? 0),
                    'deactivated' => (int) ($result['deactivated'] ?? 0),
                    'supplier_id' => $this->options['supplier_id'] ?? null,
                    'supplier_name' => $this->options['supplier_name'] ?? null,
                    'site_category_id' => $this->options['site_category_id'] ?? null,
                    'site_category_name' => $this->options['site_category_name'] ?? null,
                ],
            );

            if (($result['fatal_error'] ?? null) !== null) {
                $run->issues()->create([
                    'row_index' => null,
                    'code' => 'feed_error',
                    'severity' => 'error',
                    'message' => $result['fatal_error'],
                    'row_snapshot' => [
                        'source' => $this->options['source'] ?? null,
                    ],
                ]);
            }
        } catch (ImportRunCancelledException) {
            $run->refresh();
            $runs->markCancelled($run, $this->mode());
        } catch (Throwable $exception) {
            $runs->markFailed($run, $this->mode());

            $run->issues()->create([
                'row_index' => null,
                'code' => 'job_exception',
                'severity' => 'error',
                'message' => $exception->getMessage(),
                'row_snapshot' => null,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = ImportRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        $runs = app(ImportRunOrchestrator::class);

        if ($runs->isCancelled($run)) {
            $runs->markCancelled($run, $this->mode());

            return;
        }

        $runs->markFailed($run, $this->mode());

        $run->issues()->create([
            'row_index' => null,
            'code' => 'job_failed',
            'severity' => 'error',
            'message' => $exception?->getMessage() ?? 'Задача завершилась с ошибкой без текста исключения.',
            'row_snapshot' => null,
        ]);
    }

    private function mode(): string
    {
        return $this->write ? 'write' : 'dry-run';
    }
}
