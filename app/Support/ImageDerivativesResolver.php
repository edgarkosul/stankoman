<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageDerivativesResolver
{
    public function derivativeRelativePath(string $key, int $w): string
    {
        $folder = trim((string) config('image-derivatives.folder', 'images-webp'), '/');

        return $folder . '/' . $key . '/w' . $w . '.webp';
    }

    public function derivativeUrl(string $key, int $w): string
    {
        return $this->disk()->url($this->derivativeRelativePath($key, $w));
    }

    public function normalizeSourcePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return ltrim($path, '/');
    }

    public function normalizePicsPath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = $this->normalizeSourcePath($path);
        if (! str_starts_with($normalized, 'pics/')) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public function extractPicsPaths($value): array
    {
        if (is_array($value)) {
            $value = json_encode($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        preg_match_all('/pics[\\\\\\/][^"\'\\s<>),]+\\.[a-zA-Z0-9]{2,5}/', $value, $matches);
        if (empty($matches[0])) {
            return [];
        }

        $paths = [];
        foreach ($matches[0] as $match) {
            $path = str_replace('\\/', '/', $match);
            $path = preg_split('/[?#]/', $path, 2)[0] ?? $path;
            $path = str_replace('\\', '/', $path);
            $path = preg_replace('#/+#', '/', $path);

            $normalized = $this->normalizePicsPath($path);
            if ($normalized === null) {
                continue;
            }

            $paths[$normalized] = true;
        }

        return array_keys($paths);
    }

    public function hashKeyFromSourcePath(string $path): string
    {
        return 'h_' . sha1($this->normalizeSourcePath($path));
    }

    public function legacyKeyFromSourcePath(string $path): string
    {
        return pathinfo($this->normalizeSourcePath($path), PATHINFO_FILENAME);
    }

    public function keyFromSourcePath(string $path): string
    {
        return $this->legacyKeyFromSourcePath($path);
    }

    /**
     * @return array<int>
     */
    public function existingWidths(string $sourcePathOrKey): array
    {
        foreach ($this->keyCandidates($sourcePathOrKey) as $key) {
            $widths = $this->existingWidthsForKey($key);
            if ($widths !== []) {
                return $widths;
            }
        }

        return [];
    }

    public function buildWebpSrcset(string $sourcePathOrKey): ?string
    {
        [$key, $widths] = $this->resolveKeyAndWidths($sourcePathOrKey);

        if ($widths === []) {
            return null;
        }

        $items = [];
        foreach ($widths as $w) {
            $items[] = $this->derivativeUrl($key, $w) . ' ' . $w . 'w';
        }

        return implode(', ', $items);
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

    protected function resolveKeyAndWidths(string $sourcePathOrKey): array
    {
        foreach ($this->keyCandidates($sourcePathOrKey) as $key) {
            $widths = $this->existingWidthsForKey($key);
            if ($widths !== []) {
                return [$key, $widths];
            }
        }

        return [null, []];
    }

    protected function disk()
    {
        return Storage::disk((string) config('image-derivatives.disk', 'public'));
    }

    /**
     * @return array<int>
     */
    protected function existingWidthsForKey(string $key): array
    {
        $disk = $this->disk();
        $existing = [];

        foreach ($this->ladder() as $w) {
            $path = $this->derivativeRelativePath($key, $w);
            if (! $disk->exists($path)) {
                continue;
            }

            try {
                $size = $disk->size($path);
            } catch (\Throwable $e) {
                continue;
            }

            // Игнорируем пустые/битые файлы.
            if ($size <= 0) {
                continue;
            }

            $existing[] = $w;
        }

        sort($existing);

        return $existing;
    }

    /**
     * @return array<string>
     */
    protected function keyCandidates(string $sourcePathOrKey): array
    {
        if ($this->looksLikeSourcePath($sourcePathOrKey)) {
            $hash = $this->hashKeyFromSourcePath($sourcePathOrKey);
            $legacy = $this->legacyKeyFromSourcePath($sourcePathOrKey);

            return array_values(array_unique([$hash, $legacy]));
        }

        if ($this->looksLikeHashKey($sourcePathOrKey)) {
            return [$sourcePathOrKey];
        }

        return [$sourcePathOrKey];
    }

    protected function looksLikeSourcePath(string $value): bool
    {
        return Str::contains($value, ['/', '\\'])
            || pathinfo($value, PATHINFO_EXTENSION) !== '';
    }

    protected function looksLikeHashKey(string $value): bool
    {
        return preg_match('/^h_[0-9a-f]{40}$/', $value) === 1;
    }
}
