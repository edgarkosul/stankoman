<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'specs')) {
            return;
        }

        DB::table('products')
            ->select('id', 'specs')
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $normalized = $this->normalizeSpecsPayload($product->specs);

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'specs' => $normalized === null
                                ? null
                                : json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            }, 'id');

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('products', function (Blueprint $table): void {
                $table->json('specs')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'specs')) {
            return;
        }

        DB::table('products')
            ->select('id', 'specs')
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'specs' => $this->stringifySpecsPayload($product->specs),
                        ]);
                }
            }, 'id');

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('products', function (Blueprint $table): void {
                $table->longText('specs')->nullable()->change();
            });
        }
    }

    private function normalizeSpecsPayload(mixed $rawSpecs, string $defaultSource = 'legacy'): ?array
    {
        if ($rawSpecs === null) {
            return null;
        }

        if (is_string($rawSpecs)) {
            $trimmed = trim($rawSpecs);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return $this->normalizeSpecsArray($decoded, $defaultSource);
            }

            return $this->parseLegacySpecsText($trimmed, $defaultSource);
        }

        if (is_array($rawSpecs)) {
            return $this->normalizeSpecsArray($rawSpecs, $defaultSource);
        }

        if (is_scalar($rawSpecs)) {
            return $this->parseLegacySpecsText((string) $rawSpecs, $defaultSource);
        }

        return [];
    }

    private function normalizeSpecsArray(array $specs, string $defaultSource): array
    {
        $normalized = [];

        if (! array_is_list($specs)) {
            foreach ($specs as $name => $value) {
                $nameString = $this->sanitizeString($name);
                $valueString = $this->sanitizeString($value);

                if ($nameString === null || $valueString === null) {
                    continue;
                }

                $normalized[] = [
                    'name' => $nameString,
                    'value' => $valueString,
                    'source' => $defaultSource,
                ];
            }

            return $this->deduplicateSpecs($normalized);
        }

        foreach ($specs as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $name = $this->sanitizeString($spec['name'] ?? $spec['title'] ?? $spec['key'] ?? null);
            $value = $this->sanitizeString($spec['value'] ?? $spec['text'] ?? $spec['val'] ?? null);
            $source = $this->sanitizeString($spec['source'] ?? null) ?? $defaultSource;

            if ($name === null || $value === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'value' => $value,
                'source' => $source,
            ];
        }

        return $this->deduplicateSpecs($normalized);
    }

    private function parseLegacySpecsText(string $rawSpecs, string $defaultSource): array
    {
        $lines = preg_split('/\R+/', $rawSpecs) ?: [];
        $normalized = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s*:\s*/u', $line, 2);

            if (is_array($parts) && count($parts) === 2) {
                [$name, $value] = $parts;
            } else {
                $name = 'Raw';
                $value = $line;
            }

            $name = $this->sanitizeString($name);
            $value = $this->sanitizeString($value);

            if ($name === null || $value === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'value' => $value,
                'source' => $defaultSource,
            ];
        }

        return $this->deduplicateSpecs($normalized);
    }

    private function deduplicateSpecs(array $specs): array
    {
        $unique = [];

        foreach ($specs as $spec) {
            $name = $this->sanitizeString($spec['name'] ?? null);
            $value = $this->sanitizeString($spec['value'] ?? null);
            $source = $this->sanitizeString($spec['source'] ?? null) ?? 'legacy';

            if ($name === null || $value === null) {
                continue;
            }

            $key = mb_strtolower($name.'::'.$value);

            if (! isset($unique[$key])) {
                $unique[$key] = [
                    'name' => $name,
                    'value' => $value,
                    'source' => $source,
                ];
            }
        }

        return array_values($unique);
    }

    private function stringifySpecsPayload(mixed $rawSpecs): ?string
    {
        $normalized = $this->normalizeSpecsPayload($rawSpecs, 'rollback');

        if ($normalized === null) {
            return null;
        }

        if ($normalized === []) {
            return '';
        }

        $lines = [];

        foreach ($normalized as $spec) {
            $name = $this->sanitizeString($spec['name'] ?? null);
            $value = $this->sanitizeString($spec['value'] ?? null);

            if ($name === null || $value === null) {
                continue;
            }

            $lines[] = $name.': '.$value;
        }

        return implode(PHP_EOL, $lines);
    }

    private function sanitizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
};
