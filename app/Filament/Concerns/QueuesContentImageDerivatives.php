<?php

namespace App\Filament\Concerns;

use App\Jobs\GenerateImageDerivativesJob;
use App\Support\ImageDerivativesResolver;
use Filament\Notifications\Notification;

trait QueuesContentImageDerivatives
{
    protected function queueContentImageDerivatives(array $values, bool $force): int
    {
        $paths = $this->extractContentPicsPaths($values);
        foreach ($paths as $path) {
            GenerateImageDerivativesJob::dispatch($path, $force);
        }

        return count($paths);
    }

    protected function notifyContentImageDerivativesQueued(int $queued, bool $force): void
    {
        if ($queued === 0) {
            Notification::make()
                ->warning()
                ->title('Нет изображений для генерации')
                ->send();

            return;
        }

        $title = $force ? 'Перегенерация WebP поставлена в очередь' : 'Генерация WebP поставлена в очередь';

        Notification::make()
            ->success()
            ->title($title)
            ->body("Поставлено: {$queued}")
            ->send();
    }

    protected function hasAnyContentImages(array $values): bool
    {
        return $this->extractContentPicsPaths($values) !== [];
    }

    /**
     * @return array<int, string>
     */
    protected function extractContentPicsPaths(array $values): array
    {
        $resolver = app(ImageDerivativesResolver::class);
        $paths = [];

        foreach ($values as $value) {
            foreach ($resolver->extractPicsPaths($value) as $path) {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }
}
