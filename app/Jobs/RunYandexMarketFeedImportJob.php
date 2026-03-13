<?php

namespace App\Jobs;

use App\Exceptions\ImportErrorThresholdExceededException;
use App\Exceptions\ImportRunCancelledException;
use App\Models\ImportRun;
use App\Support\CatalogImport\Runs\ImportRunOrchestrator;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunYandexMarketFeedImportJob implements ShouldQueue
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
            (new WithoutOverlapping('catalog_import_yandex_market_feed'))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(YandexMarketFeedImportService $service, ImportRunOrchestrator $runs): void
    {
        $run = ImportRun::query()->find($this->runId);

        if (! $run) {
            Log::warning('Queued yandex import run not found', [
                'run_id' => $this->runId,
                'job_class' => self::class,
                'queue' => $this->job?->getQueue(),
                'connection' => $this->job?->getConnectionName(),
            ]);

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
                    $runs->saveProgress($run, $progress, $this->mode());

                    $thresholdExceeded = $runs->thresholdExceeded($progress, $this->options);

                    if ($thresholdExceeded !== null) {
                        throw new ImportErrorThresholdExceededException(
                            metric: (string) $thresholdExceeded['metric'],
                            threshold: $thresholdExceeded['threshold'],
                            actual: $thresholdExceeded['actual'],
                        );
                    }

                    if ($runs->isCancelled($run)) {
                        throw new ImportRunCancelledException('Импорт остановлен пользователем.');
                    }
                },
            );

            $run->refresh();

            if ($runs->isCancelled($run)) {
                $runs->markCancelled($run, $this->mode());

                return;
            }

            $runs->completeFromResult($run, $result, $this->write);

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

            foreach (($result['url_errors'] ?? []) as $urlError) {
                $run->issues()->create([
                    'row_index' => null,
                    'code' => 'parse_error',
                    'severity' => 'error',
                    'message' => (string) ($urlError['message'] ?? 'Unknown parse error.'),
                    'row_snapshot' => [
                        'url' => (string) ($urlError['url'] ?? ''),
                    ],
                ]);
            }
        } catch (ImportRunCancelledException) {
            $run->refresh();
            $runs->markCancelled($run, $this->mode());
        } catch (ImportErrorThresholdExceededException $exception) {
            $run->refresh();

            $runs->markFailed($run, $this->mode(), [
                'error_threshold_exceeded' => true,
            ]);

            $run->issues()->create([
                'row_index' => null,
                'code' => 'error_threshold_exceeded',
                'severity' => 'error',
                'message' => $exception->getMessage(),
                'row_snapshot' => $exception->toSnapshot(),
            ]);
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
