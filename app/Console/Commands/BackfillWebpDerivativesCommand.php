<?php

namespace App\Console\Commands;

use App\Jobs\GenerateImageDerivativesJob;
use App\Models\Page;
use App\Models\Slider;
use App\Support\ImageDerivativesResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackfillWebpDerivativesCommand extends Command
{
    protected $signature = 'images:webp-backfill
                            {--limit=500 : Max number of jobs to queue (0 = no limit)}
                            {--force : Regenerate even if derivatives exist}
                            {--show-skipped : Show skipped reasons and examples}
                            {--samples=5 : Max samples per skipped reason}';

    protected $description = 'Queue missing WebP derivatives for page and slider images';

    public function handle(): int
    {
        $limitOption = (int) $this->option('limit');
        $limit = $limitOption > 0 ? $limitOption : null;
        $force = (bool) $this->option('force');
        $showSkipped = (bool) $this->option('show-skipped');
        $sampleLimit = max(0, (int) $this->option('samples'));

        $resolver = app(ImageDerivativesResolver::class);
        $disk = Storage::disk((string) config('image-derivatives.disk', 'public'));

        $queued = 0;
        $ready = 0;
        $skipped = 0;
        $duplicates = 0;
        $noPageImages = 0;
        $noSliderImages = 0;
        $skipReasons = [];
        $skipSamples = [];
        $seen = [];
        $limitReached = false;

        Page::query()
            ->select(['id', 'content'])
            ->chunkById(200, function ($pages) use (
                $resolver,
                $disk,
                $force,
                $limit,
                $sampleLimit,
                &$queued,
                &$ready,
                &$skipped,
                &$duplicates,
                &$noPageImages,
                &$skipReasons,
                &$skipSamples,
                &$seen,
                &$limitReached
            ) {
                foreach ($pages as $page) {
                    $paths = $this->collectContentPaths([$page->content], $resolver);
                    if ($paths === []) {
                        $noPageImages++;
                        continue;
                    }

                    foreach ($paths as $path) {
                        if (! $this->queuePath(
                            $path,
                            'page ' . $page->id,
                            $resolver,
                            $disk,
                            $force,
                            $limit,
                            $sampleLimit,
                            $seen,
                            $queued,
                            $ready,
                            $skipped,
                            $duplicates,
                            $skipReasons,
                            $skipSamples,
                            $limitReached
                        )) {
                            return false;
                        }
                    }
                }
            });

        if (! $limitReached) {
            Slider::query()
                ->select(['id', 'slides'])
                ->whereNotNull('slides')
                ->chunkById(200, function ($sliders) use (
                    $resolver,
                    $disk,
                    $force,
                    $limit,
                    $sampleLimit,
                    &$queued,
                    &$ready,
                    &$skipped,
                    &$duplicates,
                    &$noSliderImages,
                    &$skipReasons,
                    &$skipSamples,
                    &$seen,
                    &$limitReached
                ) {
                    foreach ($sliders as $slider) {
                        $paths = $this->collectContentPaths([$slider->slides], $resolver);
                        if ($paths === []) {
                            $noSliderImages++;
                            continue;
                        }

                        foreach ($paths as $path) {
                            if (! $this->queuePath(
                                $path,
                                'slider ' . $slider->id,
                                $resolver,
                                $disk,
                                $force,
                                $limit,
                                $sampleLimit,
                                $seen,
                                $queued,
                                $ready,
                                $skipped,
                                $duplicates,
                                $skipReasons,
                                $skipSamples,
                                $limitReached
                            )) {
                                return false;
                            }
                        }
                    }
                });
        }

        $summary = [
            'queued' => $queued,
            'ready' => $ready,
            'skipped' => $skipped,
            'skipped_reasons' => $skipReasons,
            'duplicates' => $duplicates,
            'no_images' => $noPageImages + $noSliderImages,
            'no_page_images' => $noPageImages,
            'no_slider_images' => $noSliderImages,
            'unique_paths' => count($seen),
            'force' => $force,
            'limit' => $limitOption,
            'limit_reached' => $limitReached,
        ];

        if ($showSkipped && $sampleLimit > 0 && $skipSamples !== []) {
            $summary['skipped_samples'] = $skipSamples;
        }

        Log::info('WebP derivatives backfill queued', $summary);

        $this->info(
            'Queued: ' . $queued
            . '. Ready: ' . $ready
            . '. Skipped: ' . $skipped
            . '. Duplicates: ' . $duplicates
            . '. No images (pages+sliders): ' . ($noPageImages + $noSliderImages)
            . '. Unique paths: ' . count($seen)
            . '.'
        );

        if ($skipReasons !== []) {
            $this->line('Skipped by reason: ' . $this->formatSkipReasons($skipReasons));
        }

        if ($showSkipped && $sampleLimit > 0 && $skipSamples !== []) {
            $this->line('Skipped examples:');
            foreach ($skipSamples as $reason => $samples) {
                $this->line('- ' . $reason . ': ' . implode(' | ', $samples));
            }
        }

        if ($limitReached) {
            $this->warn('Limit reached.');
        }

        return self::SUCCESS;
    }

    private function collectContentPaths(array $values, ImageDerivativesResolver $resolver): array
    {
        $paths = [];
        foreach ($values as $value) {
            foreach ($resolver->extractPicsPaths($value) as $path) {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    private function queuePath(
        string $path,
        string $recordLabel,
        ImageDerivativesResolver $resolver,
        $disk,
        bool $force,
        ?int $limit,
        int $sampleLimit,
        array &$seen,
        int &$queued,
        int &$ready,
        int &$skipped,
        int &$duplicates,
        array &$skipReasons,
        array &$skipSamples,
        bool &$limitReached
    ): bool {
        if (isset($seen[$path])) {
            $duplicates++;
            return true;
        }

        $seen[$path] = true;

        if (! $disk->exists($path)) {
            $this->recordSkip($skipped, $skipReasons, $skipSamples, 'source_missing', $path, $recordLabel, $sampleLimit);
            return true;
        }

        if (! $force) {
            $srcset = $resolver->buildWebpSrcset($path);
            if ($srcset !== null) {
                $ready++;
                return true;
            }
        }

        if ($limit !== null && $queued >= $limit) {
            $limitReached = true;
            return false;
        }

        GenerateImageDerivativesJob::dispatch($path, $force);
        $queued++;

        if ($limit !== null && $queued >= $limit) {
            $limitReached = true;
            return false;
        }

        return true;
    }

    private function recordSkip(
        int &$skipped,
        array &$skipReasons,
        array &$skipSamples,
        string $reason,
        ?string $value,
        ?string $recordLabel,
        int $sampleLimit
    ): void {
        $skipped++;
        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;

        if ($sampleLimit <= 0 || $value === null) {
            return;
        }

        if (! isset($skipSamples[$reason])) {
            $skipSamples[$reason] = [];
        }

        if (count($skipSamples[$reason]) >= $sampleLimit) {
            return;
        }

        $label = $value;
        if ($recordLabel !== null && $recordLabel !== '') {
            $label = $recordLabel . ': ' . $value;
        }

        $skipSamples[$reason][] = $label;
    }

    private function formatSkipReasons(array $skipReasons): string
    {
        $parts = [];
        foreach ($skipReasons as $reason => $count) {
            $parts[] = $reason . '=' . $count;
        }

        return implode(', ', $parts);
    }
}
