<?php

namespace App\Support\Products;

use App\Models\Category;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Throwable;

class CategoryFilterImportService
{
    public function __construct(private CategoryFilterSchemaService $schemaService) {}

    /**
     * @return array<string, int>
     */
    public function importFromXlsx(ImportRun $run, Category $category, string $absPath, bool $write = true): array
    {
        $schema = $this->schemaService->build($category);

        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($absPath);
        } catch (Throwable $e) {
            return $this->failRun(
                run: $run,
                code: 'invalid_file',
                message: 'Не удалось прочитать XLSX-файл: '.$e->getMessage(),
            );
        }

        $productsSheet = $spreadsheet->getSheetByName(CategoryFilterSchemaService::PRODUCTS_SHEET);
        $metaSheet = $spreadsheet->getSheetByName(CategoryFilterSchemaService::META_SHEET);

        if (! $productsSheet || ! $metaSheet) {
            $spreadsheet->disconnectWorksheets();

            return $this->failRun(
                run: $run,
                code: 'invalid_template',
                message: 'В файле отсутствуют обязательные листы Товары и/или _meta.',
            );
        }

        $meta = $this->extractMeta($metaSheet->toArray(null, true, false, true));

        $metaCheck = $this->validateMeta($meta, $schema, $category);
        if (! $metaCheck['ok']) {
            $spreadsheet->disconnectWorksheets();

            return $this->failRun(
                run: $run,
                code: (string) $metaCheck['code'],
                message: (string) $metaCheck['message'],
                snapshot: [
                    'meta' => $meta,
                    'expected_category_id' => (int) $category->getKey(),
                    'expected_schema_hash' => (string) $schema['schema_hash'],
                ],
            );
        }

        $rows = array_values($productsSheet->toArray(null, true, false, true));

        if (($rows[0] ?? null) === null) {
            $spreadsheet->disconnectWorksheets();

            return $this->failRun(
                run: $run,
                code: 'empty_file',
                message: 'Лист Товары пуст.',
            );
        }

        $headerRow = $rows[0];
        $letters = array_keys($headerRow);
        $headers = array_values(array_map(fn ($value) => trim((string) $value), $headerRow));
        $expectedHeaders = array_values(array_map(fn ($column) => (string) $column['key'], $schema['columns']));

        if ($headers !== $expectedHeaders) {
            $spreadsheet->disconnectWorksheets();

            return $this->failRun(
                run: $run,
                code: 'invalid_header',
                message: 'Шапка листа Товары не соответствует шаблону категории.',
                snapshot: [
                    'expected' => $expectedHeaders,
                    'actual' => $headers,
                ],
            );
        }

        $run->columns = $expectedHeaders;
        $run->save();

        $dataRows = array_slice($rows, 2);

        $productIds = $this->extractProductIds($dataRows, $letters, $expectedHeaders);
        $products = $this->loadProductsForImport($productIds, $category, $schema);

        $totals = [
            'updated' => 0,
            'skipped' => 0,
            'error' => 0,
            'conflict' => 0,
            'scanned' => 0,
        ];

        $seenProductIds = [];

        foreach ($dataRows as $offset => $line) {
            $rowIndex = $offset + 3;
            $rowData = $this->rowToAssoc($line, $letters, $expectedHeaders);

            if ($this->isCompletelyEmptyRow($rowData)) {
                continue;
            }

            $totals['scanned']++;

            $productIdRaw = trim((string) ($rowData['product_id'] ?? ''));
            $productId = $this->toPositiveInt($productIdRaw);

            if (! $productId) {
                $totals['error']++;
                $this->addIssue($run, $rowIndex, 'invalid_product_id', 'Поле product_id должно быть положительным числом.', $rowData);

                continue;
            }

            if (isset($seenProductIds[$productId])) {
                $totals['error']++;
                $this->addIssue($run, $rowIndex, 'duplicate_product_id_in_file', 'Повторяющийся product_id в файле импорта.', $rowData);

                continue;
            }

            $seenProductIds[$productId] = true;

            /** @var Product|null $product */
            $product = $products->get($productId);

            if (! $product) {
                $totals['error']++;
                $this->addIssue($run, $rowIndex, 'product_not_found_in_category', 'Товар не найден в выбранной категории.', $rowData);

                continue;
            }

            $fixedError = $this->validateFixedColumns($rowData, $product);
            if ($fixedError !== null) {
                $totals['error']++;

                if ($fixedError['code'] === 'conflict_updated_at') {
                    $totals['conflict']++;
                }

                $this->addIssue($run, $rowIndex, $fixedError['code'], $fixedError['message'], $rowData);

                continue;
            }

            $valueRowsByAttribute = $product->attributeValues->keyBy('attribute_id');
            $optionRowsByAttribute = $product->attributeOptions
                ->groupBy(fn ($option): int => (int) ($option->pivot->attribute_id ?? 0));

            $changes = [];
            $rowErrors = [];

            foreach ($schema['attributes'] as $attribute) {
                $result = $this->parseAttributeChange(
                    attribute: $attribute,
                    rowData: $rowData,
                    valueRowsByAttribute: $valueRowsByAttribute,
                    optionRowsByAttribute: $optionRowsByAttribute,
                );

                if (! $result['ok']) {
                    $rowErrors[] = $result['error'];

                    continue;
                }

                if ($result['has_change']) {
                    $changes[] = $result['change'];
                }
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $error) {
                    $totals['error']++;
                    $this->addIssue(
                        run: $run,
                        rowIndex: $rowIndex,
                        code: (string) ($error['code'] ?? 'invalid_value'),
                        message: (string) ($error['message'] ?? 'Ошибка валидации значения.'),
                        rowSnapshot: $rowData,
                    );
                }

                continue;
            }

            if ($changes === []) {
                $totals['skipped']++;

                continue;
            }

            if ($write) {
                try {
                    DB::transaction(function () use ($changes, $product): void {
                        foreach ($changes as $change) {
                            $this->applyChange($product, $change);
                        }
                    });
                } catch (Throwable $e) {
                    $totals['error']++;
                    $this->addIssue(
                        run: $run,
                        rowIndex: $rowIndex,
                        code: 'row_apply_failed',
                        message: 'Не удалось применить изменения строки: '.$e->getMessage(),
                        rowSnapshot: $rowData,
                    );

                    continue;
                }
            }

            $totals['updated']++;
        }

        $spreadsheet->disconnectWorksheets();

        $totalsPayload = $this->totalsPayload($totals);

        $run->status = $write ? 'applied' : 'dry_run';
        $run->totals = $totalsPayload;
        $run->finished_at = now();
        $run->save();

        return $totalsPayload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    protected function extractMeta(array $rows): array
    {
        $meta = [];

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $values = array_values($row);
            $key = trim((string) ($values[0] ?? ''));

            if ($key === '') {
                continue;
            }

            $meta[$key] = trim((string) ($values[1] ?? ''));
        }

        return $meta;
    }

    /**
     * @param  array<string, string>  $meta
     * @param  array<string, mixed>  $schema
     * @return array{ok:bool,code?:string,message?:string}
     */
    protected function validateMeta(array $meta, array $schema, Category $category): array
    {
        if (($meta['template_type'] ?? null) !== CategoryFilterSchemaService::TEMPLATE_TYPE) {
            return [
                'ok' => false,
                'code' => 'invalid_template_type',
                'message' => 'Неверный тип шаблона. Используйте категорийный шаблон фильтров.',
            ];
        }

        $fileCategoryId = $this->toPositiveInt($meta['category_id'] ?? '');

        if ($fileCategoryId !== (int) $category->getKey()) {
            return [
                'ok' => false,
                'code' => 'category_mismatch',
                'message' => 'Файл привязан к другой категории.',
            ];
        }

        if (($meta['schema_hash'] ?? '') !== (string) $schema['schema_hash']) {
            return [
                'ok' => false,
                'code' => 'schema_mismatch',
                'message' => 'Схема категории изменилась. Скачайте новый шаблон.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $dataRows
     * @param  array<int, string>  $letters
     * @param  array<int, string>  $headers
     * @return array<int, int>
     */
    protected function extractProductIds(array $dataRows, array $letters, array $headers): array
    {
        $ids = [];

        foreach ($dataRows as $line) {
            $rowData = $this->rowToAssoc($line, $letters, $headers);

            if ($this->isCompletelyEmptyRow($rowData)) {
                continue;
            }

            $productId = $this->toPositiveInt(trim((string) ($rowData['product_id'] ?? '')));
            if ($productId) {
                $ids[] = $productId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, int>  $productIds
     * @param  array<string, mixed>  $schema
     * @return Collection<int, Product>
     */
    protected function loadProductsForImport(array $productIds, Category $category, array $schema): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        $attributeIds = collect($schema['attributes'])
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $optionAttributeIds = collect($schema['attributes'])
            ->filter(fn (array $attribute): bool => in_array((string) $attribute['template_type'], ['select', 'multiselect'], true))
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return Product::query()
            ->whereIn('id', $productIds)
            ->whereHas('categories', function ($query) use ($category): void {
                $query->where('categories.id', $category->getKey());
            })
            ->with([
                'attributeValues' => function ($query) use ($attributeIds): void {
                    if ($attributeIds === []) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->whereIn('attribute_id', $attributeIds);
                },
                'attributeOptions' => function ($query) use ($optionAttributeIds): void {
                    if ($optionAttributeIds === []) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query
                        ->whereIn('product_attribute_option.attribute_id', $optionAttributeIds)
                        ->orderBy('attribute_options.sort_order')
                        ->orderBy('attribute_options.id');
                },
            ])
            ->get(['id', 'name', 'sku', 'updated_at'])
            ->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $rowData
     * @return array{code:string,message:string}|null
     */
    protected function validateFixedColumns(array $rowData, Product $product): ?array
    {
        $fileName = trim((string) ($rowData['name'] ?? ''));
        $actualName = trim((string) $product->name);

        if ($fileName === '' || $fileName !== $actualName) {
            return [
                'code' => 'name_mismatch',
                'message' => 'Колонка name не совпадает с текущим названием товара.',
            ];
        }

        $fileSku = trim((string) ($rowData['sku'] ?? ''));
        $actualSku = trim((string) ($product->sku ?? ''));

        if ($fileSku !== $actualSku) {
            return [
                'code' => 'sku_mismatch',
                'message' => 'Колонка sku не совпадает с текущим значением товара.',
            ];
        }

        $fileUpdatedAt = trim((string) ($rowData['updated_at'] ?? ''));

        if ($fileUpdatedAt === '') {
            return [
                'code' => 'missing_updated_at',
                'message' => 'Поле updated_at обязательно для обновления.',
            ];
        }

        $expectedUpdatedAt = optional($product->updated_at)->format('Y-m-d H:i:s');

        if ($fileUpdatedAt !== $expectedUpdatedAt) {
            return [
                'code' => 'conflict_updated_at',
                'message' => "Строка устарела. expected={$expectedUpdatedAt}, got={$fileUpdatedAt}",
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @param  array<string, mixed>  $rowData
     * @return array{ok:bool,has_change:bool,change?:array<string,mixed>,error?:array{code:string,message:string}}
     */
    protected function parseAttributeChange(
        array $attribute,
        array $rowData,
        Collection $valueRowsByAttribute,
        Collection $optionRowsByAttribute,
    ): array {
        $attributeId = (int) ($attribute['attribute_id'] ?? 0);
        $attributeKey = (string) ($attribute['attribute_key'] ?? '');
        $templateType = (string) ($attribute['template_type'] ?? 'text');

        /** @var ProductAttributeValue|null $valueRow */
        $valueRow = $valueRowsByAttribute->get($attributeId);

        /** @var Collection<int, mixed> $optionRows */
        $optionRows = $optionRowsByAttribute->get($attributeId, collect());

        if ($templateType === 'range') {
            return $this->parseRangeChange($attribute, $rowData, $valueRow);
        }

        $raw = $rowData[$attributeKey] ?? null;

        if ($this->isBlankValue($raw)) {
            return ['ok' => true, 'has_change' => false];
        }

        if ($this->isClearMarker($raw)) {
            if ($templateType === 'select' || $templateType === 'multiselect') {
                $currentIds = $optionRows
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                if ($currentIds === []) {
                    return ['ok' => true, 'has_change' => false];
                }

                $action = $templateType === 'select' ? 'set_select' : 'set_multiselect';

                return [
                    'ok' => true,
                    'has_change' => true,
                    'change' => [
                        'action' => $action,
                        'attribute_id' => $attributeId,
                        'option_id' => null,
                        'option_ids' => [],
                    ],
                ];
            }

            if (! $this->hasPavValue($valueRow)) {
                return ['ok' => true, 'has_change' => false];
            }

            return [
                'ok' => true,
                'has_change' => true,
                'change' => [
                    'action' => 'clear_pav',
                    'attribute_id' => $attributeId,
                ],
            ];
        }

        if ($templateType === 'text') {
            $target = trim((string) $raw);
            $current = $valueRow && $valueRow->value_text !== null
                ? trim((string) $valueRow->value_text)
                : null;

            if ($current !== null && $current === '') {
                $current = null;
            }

            if ($target === '' || $target === $current) {
                return ['ok' => true, 'has_change' => false];
            }

            return [
                'ok' => true,
                'has_change' => true,
                'change' => [
                    'action' => 'set_text',
                    'attribute_id' => $attributeId,
                    'value_text' => $target,
                ],
            ];
        }

        if ($templateType === 'boolean') {
            $parsed = $this->parseBoolean($raw);

            if ($parsed === null) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_boolean',
                        'message' => "Недопустимое значение boolean для {$attributeKey}. Используйте Да/Нет.",
                    ],
                ];
            }

            $current = $valueRow?->value_boolean;

            if ($current === $parsed) {
                return ['ok' => true, 'has_change' => false];
            }

            return [
                'ok' => true,
                'has_change' => true,
                'change' => [
                    'action' => 'set_boolean',
                    'attribute_id' => $attributeId,
                    'value_boolean' => $parsed,
                ],
            ];
        }

        if ($templateType === 'number') {
            $parsed = $this->parseNumber($raw);

            if ($parsed === null) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_number',
                        'message' => "Недопустимое числовое значение для {$attributeKey}.",
                    ],
                ];
            }

            $quantized = $this->quantize($parsed, $attribute);
            $target = $this->convertUiToStorage($quantized, $attribute);

            if (! $target) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_number',
                        'message' => "Не удалось конвертировать числовое значение для {$attributeKey}.",
                    ],
                ];
            }

            $current = $this->currentNumberState($valueRow, $attribute);

            if ($this->numbersEqual($target['base'], $current['base'])) {
                return ['ok' => true, 'has_change' => false];
            }

            return [
                'ok' => true,
                'has_change' => true,
                'change' => [
                    'action' => 'set_number',
                    'attribute_id' => $attributeId,
                    'value_number' => $target['base'],
                    'value_si' => $target['si'],
                ],
            ];
        }

        if ($templateType === 'select') {
            $optionId = $this->resolveOptionIdByLabel($attribute, (string) $raw);

            if ($optionId === null) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'unknown_option',
                        'message' => "Для {$attributeKey} не найдено значение '{$raw}' в attribute_options.",
                    ],
                ];
            }

            $currentId = $optionRows
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->first();

            if ($currentId !== null) {
                $currentId = (int) $currentId;
            }

            if ($currentId === $optionId) {
                return ['ok' => true, 'has_change' => false];
            }

            return [
                'ok' => true,
                'has_change' => true,
                'change' => [
                    'action' => 'set_select',
                    'attribute_id' => $attributeId,
                    'option_id' => $optionId,
                ],
            ];
        }

        if ($templateType === 'multiselect') {
            $tokens = collect(explode(';', (string) $raw))
                ->map(fn ($token) => trim($token))
                ->filter(fn ($token) => $token !== '')
                ->values()
                ->all();

            if ($tokens === []) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_multiselect',
                        'message' => "Для {$attributeKey} не переданы значения multiselect.",
                    ],
                ];
            }

            $targetIds = [];

            foreach ($tokens as $token) {
                $optionId = $this->resolveOptionIdByLabel($attribute, $token);

                if ($optionId === null) {
                    return [
                        'ok' => false,
                        'has_change' => false,
                        'error' => [
                            'code' => 'unknown_option',
                            'message' => "Для {$attributeKey} не найдено значение '{$token}' в attribute_options.",
                        ],
                    ];
                }

                $targetIds[] = $optionId;
            }

            $targetIds = array_values(array_unique($targetIds));
            sort($targetIds);

            $currentIds = $optionRows
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            sort($currentIds);

            if ($currentIds === $targetIds) {
                return ['ok' => true, 'has_change' => false];
            }

            return [
                'ok' => true,
                'has_change' => true,
                'change' => [
                    'action' => 'set_multiselect',
                    'attribute_id' => $attributeId,
                    'option_ids' => $targetIds,
                ],
            ];
        }

        return ['ok' => true, 'has_change' => false];
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @param  array<string, mixed>  $rowData
     * @return array{ok:bool,has_change:bool,change?:array<string,mixed>,error?:array{code:string,message:string}}
     */
    protected function parseRangeChange(array $attribute, array $rowData, ?ProductAttributeValue $valueRow): array
    {
        $attributeId = (int) ($attribute['attribute_id'] ?? 0);
        $attributeKey = (string) ($attribute['attribute_key'] ?? '');

        $rawMin = $rowData[$attributeKey.'.min'] ?? null;
        $rawMax = $rowData[$attributeKey.'.max'] ?? null;

        $minBlank = $this->isBlankValue($rawMin);
        $maxBlank = $this->isBlankValue($rawMax);

        if ($minBlank && $maxBlank) {
            return ['ok' => true, 'has_change' => false];
        }

        if ($this->isClearMarker($rawMin) || $this->isClearMarker($rawMax)) {
            if (! $this->hasRangeValue($valueRow)) {
                return ['ok' => true, 'has_change' => false];
            }

            return [
                'ok' => true,
                'has_change' => true,
                'change' => [
                    'action' => 'clear_pav',
                    'attribute_id' => $attributeId,
                ],
            ];
        }

        $current = $this->currentRangeState($valueRow, $attribute);

        $targetMinBase = $current['min_base'];
        $targetMinSi = $current['min_si'];
        $targetMaxBase = $current['max_base'];
        $targetMaxSi = $current['max_si'];

        if (! $minBlank) {
            $parsedMin = $this->parseNumber($rawMin);

            if ($parsedMin === null) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_number',
                        'message' => "Недопустимое число в {$attributeKey}.min.",
                    ],
                ];
            }

            $convertedMin = $this->convertUiToStorage($this->quantize($parsedMin, $attribute), $attribute);

            if (! $convertedMin) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_number',
                        'message' => "Не удалось конвертировать {$attributeKey}.min.",
                    ],
                ];
            }

            $targetMinBase = $convertedMin['base'];
            $targetMinSi = $convertedMin['si'];
        }

        if (! $maxBlank) {
            $parsedMax = $this->parseNumber($rawMax);

            if ($parsedMax === null) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_number',
                        'message' => "Недопустимое число в {$attributeKey}.max.",
                    ],
                ];
            }

            $convertedMax = $this->convertUiToStorage($this->quantize($parsedMax, $attribute), $attribute);

            if (! $convertedMax) {
                return [
                    'ok' => false,
                    'has_change' => false,
                    'error' => [
                        'code' => 'invalid_number',
                        'message' => "Не удалось конвертировать {$attributeKey}.max.",
                    ],
                ];
            }

            $targetMaxBase = $convertedMax['base'];
            $targetMaxSi = $convertedMax['si'];
        }

        if ($targetMinBase !== null && $targetMaxBase !== null && $targetMinBase > $targetMaxBase) {
            [$targetMinBase, $targetMaxBase] = [$targetMaxBase, $targetMinBase];
            [$targetMinSi, $targetMaxSi] = [$targetMaxSi, $targetMinSi];
        }

        if (
            $this->numbersEqual($targetMinBase, $current['min_base'])
            && $this->numbersEqual($targetMaxBase, $current['max_base'])
        ) {
            return ['ok' => true, 'has_change' => false];
        }

        return [
            'ok' => true,
            'has_change' => true,
            'change' => [
                'action' => 'set_range',
                'attribute_id' => $attributeId,
                'value_min' => $targetMinBase,
                'value_min_si' => $targetMinSi,
                'value_max' => $targetMaxBase,
                'value_max_si' => $targetMaxSi,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array{base:float|null,si:float|null}
     */
    protected function currentNumberState(?ProductAttributeValue $valueRow, array $attribute): array
    {
        if (! $valueRow) {
            return ['base' => null, 'si' => null];
        }

        $si = $valueRow->value_si;
        if ($si === null && $valueRow->value_number !== null) {
            $si = $this->baseToSi((float) $valueRow->value_number, $attribute);
        }

        $base = $valueRow->value_number;
        if ($base === null && $si !== null) {
            $base = $this->siToBase((float) $si, $attribute);
        }

        return [
            'base' => $base === null ? null : (float) $base,
            'si' => $si === null ? null : (float) $si,
        ];
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array{min_base:float|null,min_si:float|null,max_base:float|null,max_si:float|null}
     */
    protected function currentRangeState(?ProductAttributeValue $valueRow, array $attribute): array
    {
        if (! $valueRow) {
            return [
                'min_base' => null,
                'min_si' => null,
                'max_base' => null,
                'max_si' => null,
            ];
        }

        $minSi = $valueRow->value_min_si;
        if ($minSi === null && $valueRow->value_min !== null) {
            $minSi = $this->baseToSi((float) $valueRow->value_min, $attribute);
        }

        $maxSi = $valueRow->value_max_si;
        if ($maxSi === null && $valueRow->value_max !== null) {
            $maxSi = $this->baseToSi((float) $valueRow->value_max, $attribute);
        }

        $minBase = $valueRow->value_min;
        if ($minBase === null && $minSi !== null) {
            $minBase = $this->siToBase((float) $minSi, $attribute);
        }

        $maxBase = $valueRow->value_max;
        if ($maxBase === null && $maxSi !== null) {
            $maxBase = $this->siToBase((float) $maxSi, $attribute);
        }

        return [
            'min_base' => $minBase === null ? null : (float) $minBase,
            'min_si' => $minSi === null ? null : (float) $minSi,
            'max_base' => $maxBase === null ? null : (float) $maxBase,
            'max_si' => $maxSi === null ? null : (float) $maxSi,
        ];
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function resolveOptionIdByLabel(array $attribute, string $rawLabel): ?int
    {
        $label = trim($rawLabel);

        if ($label === '') {
            return null;
        }

        $options = $attribute['options'] ?? [];

        foreach ($options as $option) {
            if (trim((string) ($option['label'] ?? '')) === $label) {
                return (int) $option['id'];
            }
        }

        $normalizedLabel = $this->normalizeOptionLabel($label);

        foreach ($options as $option) {
            $optionLabel = trim((string) ($option['label'] ?? ''));
            if ($this->normalizeOptionLabel($optionLabel) === $normalizedLabel) {
                return (int) $option['id'];
            }
        }

        return null;
    }

    protected function normalizeOptionLabel(string $value): string
    {
        $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value, 'UTF-8');
    }

    protected function parseBoolean(mixed $raw): ?bool
    {
        if ($raw === null) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $value = mb_strtolower(trim((string) $raw), 'UTF-8');

        if (in_array($value, ['да', '1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['нет', '0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }

    protected function parseNumber(mixed $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' '], ['', '', ''], $value);
        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array{base:float,si:float}|null
     */
    protected function convertUiToStorage(float $ui, array $attribute): ?array
    {
        $displayUnit = $attribute['display_unit'] ?? null;
        $baseUnit = $attribute['base_unit'] ?? null;

        if (! is_array($displayUnit) && is_array($baseUnit)) {
            $displayUnit = $baseUnit;
        }

        $displayFactor = (float) (($displayUnit['si_factor'] ?? null) ?? 1.0);
        $displayOffset = (float) (($displayUnit['si_offset'] ?? null) ?? 0.0);

        $si = $ui * $displayFactor + $displayOffset;

        if (! is_array($baseUnit)) {
            return [
                'base' => $si,
                'si' => $si,
            ];
        }

        $baseFactor = (float) ($baseUnit['si_factor'] ?? 1.0);
        $baseOffset = (float) ($baseUnit['si_offset'] ?? 0.0);

        if ($baseFactor == 0.0) {
            return null;
        }

        $base = ($si - $baseOffset) / $baseFactor;

        return [
            'base' => $base,
            'si' => $si,
        ];
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function baseToSi(float $base, array $attribute): float
    {
        $baseUnit = $attribute['base_unit'] ?? null;

        if (! is_array($baseUnit)) {
            return $base;
        }

        $factor = (float) ($baseUnit['si_factor'] ?? 1.0);
        $offset = (float) ($baseUnit['si_offset'] ?? 0.0);

        return $base * $factor + $offset;
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function siToBase(float $si, array $attribute): ?float
    {
        $baseUnit = $attribute['base_unit'] ?? null;

        if (! is_array($baseUnit)) {
            return $si;
        }

        $factor = (float) ($baseUnit['si_factor'] ?? 1.0);
        $offset = (float) ($baseUnit['si_offset'] ?? 0.0);

        if ($factor == 0.0) {
            return null;
        }

        return ($si - $offset) / $factor;
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function quantize(float $value, array $attribute): float
    {
        $numberFormat = $attribute['number_format'] ?? null;

        if (! is_array($numberFormat)) {
            return $value;
        }

        $decimals = max(0, (int) ($numberFormat['decimals'] ?? 2));
        $mode = (string) ($numberFormat['rounding'] ?? 'round');
        $factor = 10 ** $decimals;

        return match ($mode) {
            'floor' => floor($value * $factor) / $factor,
            'ceil' => ceil($value * $factor) / $factor,
            'truncate', 'trunc' => ($value >= 0
                ? floor($value * $factor)
                : ceil($value * $factor)
            ) / $factor,
            default => round($value, $decimals),
        };
    }

    protected function numbersEqual(?float $left, ?float $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) < 0.0000001;
    }

    protected function hasPavValue(?ProductAttributeValue $valueRow): bool
    {
        if (! $valueRow) {
            return false;
        }

        return $valueRow->value_text !== null
            || $valueRow->value_boolean !== null
            || $valueRow->value_number !== null
            || $valueRow->value_si !== null
            || $valueRow->value_min !== null
            || $valueRow->value_max !== null
            || $valueRow->value_min_si !== null
            || $valueRow->value_max_si !== null;
    }

    protected function hasRangeValue(?ProductAttributeValue $valueRow): bool
    {
        if (! $valueRow) {
            return false;
        }

        return $valueRow->value_min !== null
            || $valueRow->value_max !== null
            || $valueRow->value_min_si !== null
            || $valueRow->value_max_si !== null;
    }

    protected function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return trim((string) $value) === '';
    }

    protected function isClearMarker(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return mb_strtolower(trim((string) $value), 'UTF-8') === mb_strtolower(CategoryFilterSchemaService::CLEAR_MARKER, 'UTF-8');
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $letters
     * @param  array<int|string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function rowToAssoc(array $line, array $letters, array $headers): array
    {
        $assoc = [];

        foreach ($headers as $index => $header) {
            $letter = $letters[$index] ?? null;
            $assoc[$header] = $letter ? ($line[$letter] ?? null) : null;
        }

        return $assoc;
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    protected function isCompletelyEmptyRow(array $rowData): bool
    {
        foreach ($rowData as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function toPositiveInt(string $value): ?int
    {
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    protected function applyChange(Product $product, array $change): void
    {
        $attributeId = (int) ($change['attribute_id'] ?? 0);
        $action = (string) ($change['action'] ?? '');

        if (! $attributeId || $action === '') {
            return;
        }

        if ($action === 'set_select') {
            ProductAttributeOption::setSingle(
                (int) $product->getKey(),
                $attributeId,
                isset($change['option_id']) ? (int) $change['option_id'] : null,
            );

            return;
        }

        if ($action === 'set_multiselect') {
            ProductAttributeOption::setForProductAttribute(
                (int) $product->getKey(),
                $attributeId,
                array_map('intval', $change['option_ids'] ?? []),
            );

            return;
        }

        if ($action === 'clear_pav') {
            ProductAttributeValue::query()
                ->where('product_id', $product->getKey())
                ->where('attribute_id', $attributeId)
                ->delete();

            return;
        }

        $row = ProductAttributeValue::query()->firstOrNew([
            'product_id' => (int) $product->getKey(),
            'attribute_id' => $attributeId,
        ]);

        $row->value_text = null;
        $row->value_boolean = null;
        $row->value_number = null;
        $row->value_si = null;
        $row->value_min = null;
        $row->value_max = null;
        $row->value_min_si = null;
        $row->value_max_si = null;

        if ($action === 'set_text') {
            $row->value_text = (string) ($change['value_text'] ?? '');
        }

        if ($action === 'set_boolean') {
            $row->value_boolean = (bool) ($change['value_boolean'] ?? false);
        }

        if ($action === 'set_number') {
            $row->value_number = isset($change['value_number']) ? (float) $change['value_number'] : null;
            $row->value_si = isset($change['value_si']) ? (float) $change['value_si'] : null;
        }

        if ($action === 'set_range') {
            $row->value_min = isset($change['value_min']) ? (float) $change['value_min'] : null;
            $row->value_max = isset($change['value_max']) ? (float) $change['value_max'] : null;
            $row->value_min_si = isset($change['value_min_si']) ? (float) $change['value_min_si'] : null;
            $row->value_max_si = isset($change['value_max_si']) ? (float) $change['value_max_si'] : null;
        }

        $row->save();
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, int>
     */
    protected function totalsPayload(array $totals): array
    {
        return [
            'create' => 0,
            'update' => (int) ($totals['updated'] ?? 0),
            'same' => (int) ($totals['skipped'] ?? 0),
            'conflict' => (int) ($totals['conflict'] ?? 0),
            'error' => (int) ($totals['error'] ?? 0),
            'scanned' => (int) ($totals['scanned'] ?? 0),
            'updated' => (int) ($totals['updated'] ?? 0),
            'skipped' => (int) ($totals['skipped'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, int>
     */
    protected function failRun(ImportRun $run, string $code, string $message, ?array $snapshot = null): array
    {
        $this->addIssue($run, 1, $code, $message, $snapshot);

        $totals = $this->totalsPayload([
            'updated' => 0,
            'skipped' => 0,
            'error' => 1,
            'conflict' => 0,
            'scanned' => 0,
        ]);

        $run->status = 'failed';
        $run->totals = $totals;
        $run->finished_at = now();
        $run->save();

        return $totals;
    }

    /**
     * @param  array<string, mixed>|null  $rowSnapshot
     */
    protected function addIssue(
        ImportRun $run,
        int $rowIndex,
        string $code,
        string $message,
        ?array $rowSnapshot = null,
    ): void {
        $run->issues()->create([
            'row_index' => $rowIndex,
            'code' => $code,
            'severity' => 'error',
            'message' => $message,
            'row_snapshot' => $rowSnapshot,
        ]);
    }
}
