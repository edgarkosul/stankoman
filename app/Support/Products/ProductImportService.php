<?php

namespace App\Support\Products;

use App\Models\ImportRun;
use App\Models\Product;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

class ProductImportService
{
    public function whitelist(): array
    {
        return config('catalog-export.fields');
    }

    public function requiredColumns(): array
    {
        return array_keys(array_filter(
            $this->whitelist(),
            fn ($m) => ($m['required'] ?? false) === true
        ));
    }

    public function validateHeader(array $headers): array
    {
        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        $fields = $this->whitelist();

        // Маппинг: текст заголовка → ключ поля
        $headerToKey = [];
        foreach ($fields as $key => $meta) {
            $label = trim((string) ($meta['header'] ?? ''));
            if ($label !== '') {
                $headerToKey[$label] = $key;
            }
            // Для обратной совместимости: допускаем, что в шапке может быть сам key
            $headerToKey[$key] = $key;
        }

        $errors = [];
        $seen = [];
        $normalized = [];

        foreach ($headers as $h) {
            if ($h === '') {
                $errors[] = 'Empty column header is not allowed.';

                continue;
            }

            if (! isset($headerToKey[$h])) {
                $errors[] = "Unknown column header: {$h}";

                continue;
            }

            $key = $headerToKey[$h];

            if (isset($seen[$key])) {
                $errors[] = "Duplicate column header: {$h} (maps to {$key})";

                continue;
            }

            $seen[$key] = true;
            $normalized[] = $key;
        }

        foreach ($this->requiredColumns() as $req) {
            if (! in_array($req, $normalized, true)) {
                $errors[] = "Missing required column: {$req}";
            }
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'normalized' => $normalized, // массив ключей полей в порядке колонок
        ];
    }

    /** Заглушка совместимости — не используется сейчас. */
    public function dryRun(ImportRun $run, Collection $rows): array
    {
        return [
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'conflict' => 0,
            'error' => 0,
            'scanned' => $rows->count(),
        ];
    }

    public function dryRunFromXlsx(ImportRun $run, string $absPath, array $opts = []): array
    {
        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($absPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, true); // A,B,...,AA...
        if (count($rows) < 2) {
            $run->status = 'failed';
            $run->finished_at = now();
            $run->save();

            return [
                'totals' => [
                    'create' => 0,
                    'update' => 0,
                    'same' => 0,
                    'conflict' => 0,
                    'error' => 1,
                    'scanned' => 0,
                ],
                'preview' => [
                    'create' => [],
                    'update' => [],
                    'conflict' => [],
                ],
            ];
        }

        // ----- 1) Заголовок
        $headerRow = array_shift($rows);
        $letters = array_keys($headerRow); // ['A','B',...]
        $rawHeaders = array_values(array_map(static fn ($v) => trim((string) $v), $headerRow));
        $headerCheck = $this->validateHeader($rawHeaders);
        if (! $headerCheck['ok']) {
            foreach ($headerCheck['errors'] as $msg) {
                $run->issues()->create([
                    'row_index' => 1,
                    'code' => 'invalid_header',
                    'severity' => 'error',
                    'message' => $msg,
                    'row_snapshot' => ['headers' => $rawHeaders],
                ]);
            }

            $run->status = 'failed';
            $run->columns = $rawHeaders;
            $run->finished_at = now();
            $run->save();

            return [
                'totals' => [
                    'create' => 0,
                    'update' => 0,
                    'same' => 0,
                    'conflict' => 0,
                    'error' => count($headerCheck['errors']),
                    'scanned' => 0,
                ],
                'preview' => [
                    'create' => [],
                    'update' => [],
                    'conflict' => [],
                ],
            ];
        }

        $headers = $headerCheck['normalized']; // массив ключей полей
        $run->columns = $headers;
        $run->save();

        // ----- 2) Сбор строк
        $fileNames = [];
        $rowObjects = [];

        foreach ($rows as $n => $line) {
            $rowIndex = $n + 2; // 1 — заголовок
            $assoc = [];

            foreach ($headers as $i => $h) {
                $letter = $letters[$i] ?? null;
                $assoc[$h] = $letter !== null ? ($line[$letter] ?? null) : null;
            }

            $nameRaw = trim((string) ($assoc['name'] ?? ''));
            if ($nameRaw === '') {
                $rowObjects[] = [
                    'row_index' => $rowIndex,
                    'data' => $assoc,
                    'error' => 'missing_name',
                ];

                continue;
            }

            $norm = NameNormalizer::normalize($nameRaw);
            $fileNames[] = $norm;
            $rowObjects[] = [
                'row_index' => $rowIndex,
                'data' => $assoc,
                'name_norm' => $norm,
            ];
        }

        // дубликаты в самом файле
        $seen = [];
        foreach ($rowObjects as $ro) {
            if (! isset($ro['name_norm'])) {
                continue;
            }
            if (isset($seen[$ro['name_norm']])) {
                $run->issues()->create([
                    'row_index' => $ro['row_index'],
                    'code' => 'duplicate_in_file',
                    'severity' => 'error',
                    'message' => "Duplicate name in file (normalized): {$ro['name_norm']}",
                    'row_snapshot' => $ro['data'],
                ]);
            }
            $seen[$ro['name_norm']] = true;
        }

        // ----- 3) Подгружаем существующие
        $namesUnique = array_values(array_unique(array_filter($fileNames)));
        $whitelist = $this->whitelist();

        $importCols = [];
        foreach ($headers as $h) {
            $meta = $whitelist[$h] ?? null;
            if ($meta && ($meta['importable'] ?? false) && ! ($meta['virtual'] ?? false)) {
                $importCols[] = $h; // только реальные БД-поля
            }
        }

        $selectCols = array_values(array_unique(array_merge(
            ['id', 'name', 'name_normalized', 'updated_at'],
            $importCols,
        )));

        // Полный список существующих продуктов по name_normalized
        $existingAll = Product::query()
            ->whereIn('name_normalized', $namesUnique)
            ->get($selectCols)
            ->keyBy('name_normalized');

        // Если заданы фильтры — вычисляем, какие ID реально участвуют в обновлении
        $allowedIds = null;
        $filters = $opts['filters'] ?? null;

        if (is_array($filters)) {
            $filteredQuery = Product::query()->whereIn('name_normalized', $namesUnique);
            $filteredQuery = $this->applyFiltersToQuery($filteredQuery, $filters);

            $allowedIds = $filteredQuery
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // ----- 4) Обход строк
        $totals = [
            'create' => 0,
            'update' => 0,
            'same' => 0,
            'conflict' => 0,
            'error' => 0,
            'scanned' => count($rowObjects),
        ];

        // Превью для модалок: только лёгкие данные по строкам
        $preview = [
            'create' => [],
            'update' => [],
            'conflict' => [],
        ];

        foreach ($rowObjects as $ro) {
            $rowIndex = $ro['row_index'];
            $data = $ro['data'];

            if (($ro['error'] ?? null) === 'missing_name') {
                $totals['error']++;
                $run->issues()->create([
                    'row_index' => $rowIndex,
                    'code' => 'missing_name',
                    'severity' => 'error',
                    'message' => 'Column "name" is required and must be non-empty.',
                    'row_snapshot' => $data,
                ]);

                continue;
            }

            $norm = $ro['name_norm'];
            $product = $existingAll->get($norm);

            if ($product) {
                // Фильтр по категориям/статусу:
                if (is_array($allowedIds) && ! in_array((int) $product->id, $allowedIds, true)) {
                    // Вне выборки — считаем как "без изменений" и не трогаем
                    $totals['same']++;

                    continue;
                }

                // optimistic-lock по updated_at
                $expected = optional($product->updated_at)->format('Y-m-d H:i:s');
                $fromFile = trim((string) ($data['updated_at'] ?? ''));
                if ($fromFile === '') {
                    $totals['error']++;
                    $run->issues()->create([
                        'row_index' => $rowIndex,
                        'code' => 'missing_updated_at',
                        'severity' => 'error',
                        'message' => 'Column "updated_at" is required for existing product.',
                        'row_snapshot' => $data,
                    ]);

                    continue;
                }
                if ($fromFile !== $expected) {
                    $totals['conflict']++;
                    $run->issues()->create([
                        'row_index' => $rowIndex,
                        'code' => 'conflict_updated_at',
                        'severity' => 'error',
                        'message' => "Row outdated. expected={$expected}, got={$fromFile}",
                        'row_snapshot' => $data,
                    ]);

                    // Для модалки "Конфликтов"
                    $preview['conflict'][] = $this->makeRowPreview($rowIndex, $data, $product);

                    continue;
                }

                // типы
                $typeErrors = $this->validateTypes($data, $headers, $whitelist);
                foreach ($typeErrors as $msg) {
                    $totals['error']++;
                    $run->issues()->create([
                        'row_index' => $rowIndex,
                        'code' => 'invalid_type',
                        'severity' => 'error',
                        'message' => $msg,
                        'row_snapshot' => $data,
                    ]);
                }
                if (! empty($typeErrors)) {
                    continue;
                }

                // new_name
                $newName = trim((string) ($data['new_name'] ?? ''));
                if ($newName !== '') {
                    $newNorm = NameNormalizer::normalize($newName);
                    if ($newNorm !== $norm) {
                        $existsNew = Product::query()->where('name_normalized', $newNorm)->exists();
                        if ($existsNew) {
                            $totals['error']++;
                            $run->issues()->create([
                                'row_index' => $rowIndex,
                                'code' => 'new_name_taken',
                                'severity' => 'error',
                                'message' => 'new_name already used by another product',
                                'row_snapshot' => $data,
                            ]);

                            continue;
                        }
                    }
                }

                // есть ли реальные изменения
                if ($this->rowHasChanges($product, $data, $headers, $whitelist)) {
                    $totals['update']++;
                    $preview['update'][] = $this->makeRowPreview($rowIndex, $data, $product);
                } else {
                    $totals['same']++;
                }

                continue;
            }

            // новая строка — updated_at может быть пустым
            $typeErrors = $this->validateTypes($data, $headers, $whitelist);
            foreach ($typeErrors as $msg) {
                $totals['error']++;
                $run->issues()->create([
                    'row_index' => $rowIndex,
                    'code' => 'invalid_type',
                    'severity' => 'error',
                    'message' => $msg,
                    'row_snapshot' => $data,
                ]);
            }
            if (! empty($typeErrors)) {
                continue;
            }

            $totals['create']++;
            $preview['create'][] = $this->makeRowPreview($rowIndex, $data, null);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // В totals в БД пишем расширенную структуру:
        // - классические счётчики (create/update/...)
        // - _preview — лёгкие данные для модалок
        // - _filters — фильтры, с которыми запускался dry-run (на будущее)
        $totalsForRun = $totals;
        $totalsForRun['_preview'] = $preview;
        $totalsForRun['_filters'] = $opts['filters'] ?? null;

        $run->status = 'dry_run';
        $run->totals = $totalsForRun;
        $run->finished_at = now();
        $run->save();

        // наружу по-прежнему возвращаем только счётчики
        return [
            'totals' => $totals,
            'preview' => $preview,
        ];
    }

    /**
     * Лёгкий снэпшот строки для превью в модалках.
     */
    protected function makeRowPreview(int $rowIndex, array $data, ?Product $product = null): array
    {
        $name = (string) ($data['name'] ?? '');
        $newName = trim((string) ($data['new_name'] ?? ''));

        return [
            'row' => $rowIndex,
            'id' => $product?->id,
            'name' => $name,
            'new_name' => $newName !== '' ? $newName : null,
            'sku' => (string) ($data['sku'] ?? ''),
            'brand' => (string) ($data['brand'] ?? ''),
        ];
    }

    protected function decimalScale(string $type): int
    {
        return preg_match('/decimal\s*\(\s*\d+\s*,\s*(\d+)\s*\)/i', $type, $m) ? (int) $m[1] : 2;
    }

    /** Проверка типов на этапе dry-run (только присутствующие колонки). */
    protected function validateTypes(array $data, array $headers, array $whitelist): array
    {
        $errors = [];

        foreach ($headers as $h) {
            $meta = $whitelist[$h] ?? null;
            if (! $meta || ! ($meta['importable'] ?? false) || ($meta['virtual'] ?? false)) {
                continue;
            }

            $raw = $data[$h] ?? null;
            if ($raw === '' || $raw === null) {
                continue;
            }

            $type = $meta['type'] ?? 'string';

            $ok = match (true) {
                str_starts_with($type, 'decimal') => is_numeric($raw),
                $type === 'integer' => filter_var($raw, FILTER_VALIDATE_INT) !== false || is_numeric($raw),
                $type === 'boolean' => true, // любое приведём через canonicalBoolean()
                default => true,
            };

            if (! $ok) {
                $errors[] = "Invalid value for {$h}: {$raw}";
            }
        }

        return $errors;
    }

    protected function canonical($v, string $type)
    {
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') {
                $v = null;
            }
        }

        return match (true) {
            str_starts_with($type, 'decimal') => $this->canonicalDecimal($v, $type),
            $type === 'integer' => $this->canonicalInteger($v),
            $type === 'boolean' => $this->canonicalBoolean($v),
            default => ($v === null ? null : (string) $v),
        };
    }

    protected function canonicalDecimal($v, string $type)
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return (string) $v;
        }

        $scale = 2;
        if (preg_match('/decimal\s*\(\s*\d+\s*,\s*(\d+)\s*\)/i', $type, $m)) {
            $scale = (int) $m[1];
        }

        return number_format((float) $v, $scale, '.', '');
    }

    protected function canonicalInteger($v)
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return (string) $v;
        }

        return (string) ((int) (float) $v);
    }

    protected function canonicalBoolean($v)
    {
        if ($v === null || $v === '') {
            return null;
        }

        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return in_array($v, [0, 1], true) ? (string) $v : (string) $v;
        }
        if (is_float($v)) {
            $i = (int) $v;

            return ($v == $i && in_array($i, [0, 1], true)) ? (string) $i : (string) $v;
        }

        $s = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string) $v), 'UTF-8')
            : strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes', 'y', 'on', 'да', 'истина', 'верно'], true) ? '1' : '0';
    }

    protected function rowHasChanges(Product $product, array $data, array $headers, array $whitelist): bool
    {

        foreach ($headers as $h) {

            if (in_array($h, ['name', 'new_name', 'updated_at'], true)) {
                continue;
            }
            $meta = $whitelist[$h] ?? null;
            if (! $meta || ! ($meta['importable'] ?? false) || ($meta['virtual'] ?? false)) {
                continue;
            }

            $type = $meta['type'] ?? 'string';
            $dbValue = $product->getAttribute($h);
            if (! array_key_exists($h, $product->getAttributes())) {
                $dbValue = Product::query()->whereKey($product->id)->value($h);
            }
            $xlsValue = $data[$h] ?? null;

            $dbCanon = $this->canonical($dbValue, $type);
            $xlsCanon = $this->canonical($xlsValue, $type);

            if ($dbCanon !== $xlsCanon) {
                return true;
            }
        }

        return false;
    }

    protected function toDatabaseValue($canon, string $type)
    {
        if ($canon === null) {
            return null;
        }

        return match (true) {
            str_starts_with($type, 'decimal') => (string) $canon,     // decimal как строка с точкой
            $type === 'integer' => (int) $canon,
            $type === 'boolean' => ($canon === '1' || $canon === 1 || $canon === true) ? 1 : 0,
            default => (string) $canon,
        };
    }

    /** Сформировать payload изменённых полей для UPDATE (только importable, присутствующих в XLSX, реально отличающихся) */
    protected function buildUpdatePayload(Product $product, array $data, array $headers, array $whitelist): array
    {
        $payload = [];

        foreach ($headers as $h) {
            if (in_array($h, ['name', 'new_name', 'updated_at'], true)) {
                continue;
            }
            $meta = $whitelist[$h] ?? null;
            if (! $meta || ! ($meta['importable'] ?? false) || ($meta['virtual'] ?? false)) {
                continue;
            }

            $type = $meta['type'] ?? 'string';
            $dbValue = $product->getAttribute($h);
            $xlsValue = $data[$h] ?? null;

            $dbCanon = $this->canonical($dbValue, $type);
            $xlsCanon = $this->canonical($xlsValue, $type);

            if ($dbCanon !== $xlsCanon) {
                $payload[$h] = $this->toDatabaseValue($xlsCanon, $type);
            }
        }

        // безопасное переименование ключа через new_name
        $newName = trim((string) ($data['new_name'] ?? ''));
        if ($newName !== '') {
            $newNorm = NameNormalizer::normalize($newName);
            if ($newNorm !== $product->name_normalized) {
                $existsNew = Product::query()->where('name_normalized', $newNorm)->exists();
                if (! $existsNew) {
                    $payload['name'] = $newName;
                    $payload['name_normalized'] = $newNorm;
                } else {
                    // конфликт имени — оставим на уровень issues вызывающему коду
                    $payload['__rename_conflict__'] = $newName;
                }
            }
        }

        return $payload;
    }

    /** Построить payload для CREATE (только importable и присутствующие в XLSX) */
    protected function buildCreatePayload(array $data, array $headers, array $whitelist): array
    {
        $payload = [];

        foreach ($headers as $h) {
            if (in_array($h, ['new_name', 'updated_at'], true)) {
                continue;
            }
            $meta = $whitelist[$h] ?? null;
            if (! $meta || ! ($meta['importable'] ?? false) || ($meta['virtual'] ?? false)) {
                continue;
            }
            $type = $meta['type'] ?? 'string';
            $xlsValue = $data[$h] ?? null;
            $xlsCanon = $this->canonical($xlsValue, $type);

            $payload[$h] = $this->toDatabaseValue($xlsCanon, $type);
        }

        // ключевые поля
        $payload['name'] = (string) ($data['name'] ?? '');
        $payload['name_normalized'] = NameNormalizer::normalize($payload['name']);

        $payload['is_active'] = false;
        $payload['in_stock'] = true;

        return $payload;
    }

    /**
     * Применить изменения из XLSX (идентичная логика dry-run, но с записью).
     * Опции:
     *  - write: bool — реально писать в БД (true) или только посчитать (false).
     *  - limit: int  — ограничить число обрабатываемых строк (для отладки).
     */
    public function applyFromXlsx(ImportRun $run, string $absPath, array $opts = []): array
    {
        $write = (bool) ($opts['write'] ?? false);
        $limit = (int) ($opts['limit'] ?? PHP_INT_MAX);
        $filters = $opts['filters'] ?? null;

        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($absPath)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, true);

        if (count($rows) < 2) {
            $run->issues()->create([
                'row_index' => 1,
                'code' => 'empty_file',
                'severity' => 'error',
                'message' => 'File has no data rows.',
                'row_snapshot' => null,
            ]);

            return ['created' => 0, 'updated' => 0, 'same' => 0, 'conflict' => 0, 'error' => 1, 'scanned' => 0];
        }

        // 1) Шапка
        $headerRow = array_shift($rows);
        $letters = array_keys($headerRow);
        $rawHeaders = array_values(array_map(fn ($v) => trim((string) $v), $headerRow));

        $headerCheck = $this->validateHeader($rawHeaders);
        if (! $headerCheck['ok']) {
            foreach ($headerCheck['errors'] as $msg) {
                $run->issues()->create([
                    'row_index' => 1,
                    'code' => 'invalid_header',
                    'severity' => 'error',
                    'message' => $msg,
                    'row_snapshot' => ['headers' => $rawHeaders],
                ]);
            }

            return ['created' => 0, 'updated' => 0, 'same' => 0, 'conflict' => 0, 'error' => count($headerCheck['errors']), 'scanned' => 0];
        }
        $headers = $headerCheck['normalized']; // ключи полей

        // Проверяем, что заголовки (по ключам) те же, что и в dry-run
        if (is_array($run->columns) && $run->columns !== $headers) {
            $run->issues()->create([
                'row_index' => 1,
                'code' => 'header_changed',
                'severity' => 'error',
                'message' => 'Headers changed since dry-run.',
                'row_snapshot' => ['was' => $run->columns, 'now' => $headers],
            ]);

            return ['created' => 0, 'updated' => 0, 'same' => 0, 'conflict' => 0, 'error' => 1, 'scanned' => 0];
        }

        // 2) Индексируем существующие продукты (и подгружаем реально нужные столбцы)
        $whitelist = $this->whitelist();

        $importCols = [];
        foreach ($headers as $h) {
            $meta = $whitelist[$h] ?? null;
            if ($meta && ($meta['importable'] ?? false) && ! ($meta['virtual'] ?? false)) {
                $importCols[] = $h;
            }
        }

        // Собираем normalize(name) и normalize(new_name) из файла
        $fileNames = [];
        $rowAssoc = []; // храним ассоц-строки для повторного использования

        foreach ($rows as $n => $line) {
            if (count($fileNames) >= $limit) {
                break;
            }

            $assoc = [];
            foreach ($headers as $i => $h) {
                $letter = $letters[$i] ?? null;
                $assoc[$h] = $letter ? ($line[$letter] ?? null) : null;
            }
            $rowAssoc[$n] = $assoc;

            $nameRaw = trim((string) ($assoc['name'] ?? ''));
            if ($nameRaw === '') {
                continue;
            }

            $norm = NameNormalizer::normalize($nameRaw);
            $fileNames[] = $norm;

            $newNameRaw = trim((string) ($assoc['new_name'] ?? ''));
            if ($newNameRaw !== '') {
                $newNorm = NameNormalizer::normalize($newNameRaw);
                if ($newNorm !== $norm) {
                    $fileNames[] = $newNorm;
                }
            }
        }

        $namesUnique = array_values(array_unique(array_filter($fileNames)));
        $selectCols = array_values(array_unique(array_merge(
            ['id', 'name', 'name_normalized', 'updated_at'],
            $importCols
        )));

        // Полный список существующих продуктов по name_normalized
        $existingAll = Product::query()
            ->whereIn('name_normalized', $namesUnique)
            ->get($selectCols)
            ->keyBy('name_normalized');

        // Если заданы фильтры — вычисляем, какие ID реально участвуют в обновлении
        $allowedIds = null;
        if (is_array($filters)) {
            $filteredQuery = Product::query()->whereIn('name_normalized', $namesUnique);
            $filteredQuery = $this->applyFiltersToQuery($filteredQuery, $filters);

            $allowedIds = $filteredQuery
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // 3) Проходим строки
        $totals = ['created' => 0, 'updated' => 0, 'same' => 0, 'conflict' => 0, 'error' => 0, 'scanned' => 0];

        DB::beginTransaction();
        try {
            $processed = 0;

            foreach ($rows as $n => $line) {
                if ($processed >= $limit) {
                    break;
                }

                $rowIndex = $n + 2; // с учётом шапки
                $data = $rowAssoc[$n] ?? [];

                $processed++;
                $totals['scanned']++;

                $nameRaw = trim((string) ($data['name'] ?? ''));
                if ($nameRaw === '') {
                    $totals['error']++;
                    $run->issues()->create([
                        'row_index' => $rowIndex,
                        'code' => 'missing_name',
                        'severity' => 'error',
                        'message' => 'Column "name" is required and must be non-empty.',
                        'row_snapshot' => $data,
                    ]);

                    continue;
                }

                $newNameRaw = trim((string) ($data['new_name'] ?? ''));
                $norm = NameNormalizer::normalize($nameRaw);
                $newNorm = $newNameRaw !== ''
                    ? NameNormalizer::normalize($newNameRaw)
                    : null;

                $productOld = $existingAll->get($norm);
                $productNew = $newNorm ? $existingAll->get($newNorm) : null;

                $isRename = ($newNameRaw !== '') && $newNorm && $newNorm !== $norm;

                // --- Специальная логика вокруг переименования через new_name ---
                if ($isRename) {
                    if ($productOld && ! $productNew) {
                        // нормальный сценарий: есть товар по старому имени, по новому нет
                        // если фильтры заданы и товар вне выборки — считаем "без изменений"
                        if (is_array($allowedIds) && ! in_array((int) $productOld->id, $allowedIds, true)) {
                            $totals['same']++;

                            continue;
                        }

                        $product = $productOld;
                    } elseif (! $productOld && $productNew) {
                        // переименование уже применено ранее — повторный импорт старого файла
                        $totals['conflict']++;
                        $run->issues()->create([
                            'row_index' => $rowIndex,
                            'code' => 'rename_already_applied',
                            'severity' => 'error',
                            'message' => 'Переименование уже было применено ранее. Этот файл создан до последнего изменения товара, пожалуйста, сделайте новую выгрузку.',
                            'row_snapshot' => $data,
                        ]);

                        continue;
                    } elseif (! $productOld && ! $productNew) {
                        // не нашли ни старое, ни новое имя — файл не про текущее состояние
                        $totals['error']++;
                        $run->issues()->create([
                            'row_index' => $rowIndex,
                            'code' => 'rename_source_not_found',
                            'severity' => 'error',
                            'message' => 'Не найден товар для переименования ни по старому, ни по новому имени. Возможно, файл устарел. Пожалуйста, сделайте новую выгрузку.',
                            'row_snapshot' => $data,
                        ]);

                        continue;
                    } else {
                        // $productOld && $productNew && разные id
                        $totals['conflict']++;
                        $run->issues()->create([
                            'row_index' => $rowIndex,
                            'code' => 'rename_conflict',
                            'severity' => 'error',
                            'message' => 'В базе уже есть другой товар с таким новым именем, переименование невозможно.',
                            'row_snapshot' => $data,
                        ]);

                        continue;
                    }
                } else {
                    $product = $productOld;

                    // Фильтры: если продукт есть и он вне выборки — не трогаем его
                    if ($product && is_array($allowedIds) && ! in_array((int) $product->id, $allowedIds, true)) {
                        $totals['same']++;

                        continue;
                    }
                }

                // типы
                $typeErrors = $this->validateTypes($data, $headers, $whitelist);
                if (! empty($typeErrors)) {
                    foreach ($typeErrors as $msg) {
                        $totals['error']++;
                        $run->issues()->create([
                            'row_index' => $rowIndex,
                            'code' => 'invalid_type',
                            'severity' => 'error',
                            'message' => $msg,
                            'row_snapshot' => $data,
                        ]);
                    }

                    continue;
                }

                if ($product) {
                    // optimistic lock
                    $expected = optional($product->updated_at)->format('Y-m-d H:i:s');
                    $fromFile = trim((string) ($data['updated_at'] ?? ''));

                    if ($fromFile === '') {
                        $totals['error']++;
                        $run->issues()->create([
                            'row_index' => $rowIndex,
                            'code' => 'missing_updated_at',
                            'severity' => 'error',
                            'message' => 'Column "updated_at" is required for existing product.',
                            'row_snapshot' => $data,
                        ]);

                        continue;
                    }
                    if ($fromFile !== $expected) {
                        $totals['conflict']++;
                        $run->issues()->create([
                            'row_index' => $rowIndex,
                            'code' => 'conflict_updated_at',
                            'severity' => 'error',
                            'message' => "Row outdated. expected={$expected}, got={$fromFile}",
                            'row_snapshot' => $data,
                        ]);

                        continue;
                    }

                    // diff payload
                    $payload = $this->buildUpdatePayload($product, $data, $headers, $whitelist);

                    if (isset($payload['__rename_conflict__'])) {
                        // кейс, когда конфликт с товаром, не фигурирующим в файле (проверка через exists())
                        $totals['error']++;
                        $run->issues()->create([
                            'row_index' => $rowIndex,
                            'code' => 'new_name_taken',
                            'severity' => 'error',
                            'message' => 'new_name already used by another product: '.$payload['__rename_conflict__'],
                            'row_snapshot' => $data,
                        ]);
                        unset($payload['__rename_conflict__']);

                        continue;
                    }

                    if (empty($payload)) {
                        $totals['same']++;
                    } else {
                        if ($write) {
                            Product::query()->whereKey($product->id)->update($payload);
                        }
                        $totals['updated']++;
                    }
                } else {
                    // create
                    // Важный момент: если $isRename === true, мы до сюда не дойдём — см. логику выше.
                    $createPayload = $this->buildCreatePayload($data, $headers, $whitelist);

                    if ($write) {
                        $created = Product::create($createPayload);

                        // попытка прикрепить к служебной категории staging, если есть модель Category и связь
                        $stagingSlug = config('catalog-export.staging_category_slug');
                        if ($stagingSlug && class_exists(\App\Models\Category::class) && method_exists($created, 'categories')) {
                            $cat = \App\Models\Category::query()->where('slug', $stagingSlug)->first();
                            if ($cat) {
                                $created->categories()->syncWithoutDetaching([$cat->id]);
                            }
                        }
                    }

                    $totals['created']++;
                }
            }

            if ($write) {
                $prevTotals = (array) ($run->totals ?? []);
                $prevTotals['applied'] = $totals;
                $run->status = 'applied';
                $run->totals = $prevTotals;
                $run->finished_at = now();
                $run->save();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $totals['error']++;
            $run->issues()->create([
                'row_index' => null,
                'code' => 'exception',
                'severity' => 'error',
                'message' => 'Apply failed: '.$e->getMessage(),
                'row_snapshot' => null,
            ]);
        }

        $sheet->getParent()->disconnectWorksheets();
        unset($sheet);

        return $totals;
    }

    /**
     * Применить фильтры импорта к запросу Product::
     *
     * filters:
     *  - category_ids: int[]
     *  - only_active: bool
     *  - only_stock:  bool
     */
    protected function applyFiltersToQuery(Builder $query, array $filters): Builder
    {
        $categoryIds = $filters['category_ids'] ?? [];
        if (is_array($categoryIds)) {
            $categoryIds = array_filter($categoryIds, fn ($id) => $id !== null && $id !== '');
            if (! empty($categoryIds)) {
                $query->whereHas('categories', function (Builder $q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        if (! empty($filters['only_active'])) {
            $query->where('is_active', true);
        }

        if (! empty($filters['only_stock'])) {
            $query->where('in_stock', true);
        }

        return $query;
    }
}
