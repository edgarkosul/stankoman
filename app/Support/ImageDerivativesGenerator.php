<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;

class ImageDerivativesGenerator
{
    public function __construct(
        protected ImageDerivativesResolver $resolver,
    ) {}

    public function generateFromSourcePath(string $sourcePath, bool $force = false): ImageDerivativesGenerationResult
    {
        $disk = $this->disk();
        $normalizedPath = $this->resolver->normalizeSourcePath($sourcePath);
        $key = $this->resolver->hashKeyFromSourcePath($normalizedPath);
        $result = new ImageDerivativesGenerationResult($key, $normalizedPath);

        if (! $disk->exists($normalizedPath)) {
            $result->skipped['source'] = 'missing';
            $result->status = 'fail';
            return $result;
        }

        $lock = Cache::lock('image-derivatives:' . $key, 600);
        if (! $lock->get()) {
            $result->skipped['lock'] = 'locked';
            $result->status = 'partial';
            return $result;
        }

        try {
            $sourceFile = $disk->path($normalizedPath);
            $sourceImage = Image::read($sourceFile);
            $sourceWidth = $sourceImage->width();

            $folder = trim((string) config('image-derivatives.folder', 'images-webp'), '/');
            $targetDir = $folder . '/' . $key;
            $disk->makeDirectory($targetDir);

            foreach ($this->ladder() as $w) {
                if ($w > $sourceWidth) {
                    $result->skipped[(string) $w] = 'too_large';
                    continue;
                }

                $finalPath = $targetDir . '/w' . $w . '.webp';
                $finalExists = $disk->exists($finalPath);
                $finalValid = $finalExists ? $this->validFileExists($finalPath) : false;

                if (! $force && $finalValid) {
                    $result->skipped[(string) $w] = 'exists';
                    continue;
                }

                if ($finalExists && ($force || ! $finalValid)) {
                    $disk->delete($finalPath);
                }

                $tmpPath = null;
                try {
                    $image = clone $sourceImage;
                    $image->scaleDown($w);
                    $encoded = $this->encodeWebp($image, $w);

                    $tmpPath = $targetDir . '/w' . $w . '.webp.tmp-' . Str::random(8);
                    $disk->put($tmpPath, $encoded);

                    if (! $this->validFileExists($tmpPath)) {
                        $disk->delete($tmpPath);
                        $result->skipped[(string) $w] = 'error: empty';
                        continue;
                    }

                    $disk->move($tmpPath, $finalPath);

                    if (! $this->validFileExists($finalPath)) {
                        $disk->delete($tmpPath);
                        $result->skipped[(string) $w] = 'error: move_failed';
                        continue;
                    }

                    $result->generatedWidths[] = $w;
                } catch (\Throwable $e) {
                    if ($tmpPath && $disk->exists($tmpPath)) {
                        $disk->delete($tmpPath);
                    }
                    $result->skipped[(string) $w] = 'error: ' . Str::limit($e->getMessage(), 180, '');
                }
            }

            $result->status = $this->determineStatus($result);
        } catch (\Throwable $e) {
            $result->skipped['error'] = 'error: ' . Str::limit($e->getMessage(), 180, '');
            $result->status = 'fail';
        } finally {
            $lock->release();
        }

        return $result;
    }

    protected function encodeWebp(ImageInterface $image, int $width): string
    {
        $quality = (int) config('image-derivatives.webp_quality', 85);
        $strip = (bool) config('image-derivatives.strip_metadata', false);

        return (string) $image->toWebp($quality, $strip);
    }

    /**
     * @return array<int>
     */
    protected function ladder(): array
    {
        return array_values(array_map(
            'intval',
            config('image-derivatives.ladder', [])
        ));
    }

    protected function validFileExists(string $path): bool
    {
        $disk = $this->disk();
        if (! $disk->exists($path)) {
            return false;
        }

        try {
            return $disk->size($path) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function determineStatus(ImageDerivativesGenerationResult $result): string
    {
        $hasError = false;
        foreach ($result->skipped as $reason) {
            if ($reason === 'locked' || $reason === 'missing' || Str::startsWith($reason, 'error:')) {
                $hasError = true;
                break;
            }
        }

        if ($hasError) {
            return $result->generatedWidths !== [] ? 'partial' : 'fail';
        }

        return 'success';
    }

    protected function disk()
    {
        return Storage::disk((string) config('image-derivatives.disk', 'public'));
    }
}
