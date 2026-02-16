<?php

namespace App\Support\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CategoryFilterTemplateExportService
{
    public function __construct(private CategoryFilterSchemaService $schemaService) {}

    /**
     * @return array{path:string,downloadName:string,schema_hash:string,category_id:int}
     */
    public function export(Category $category): array
    {
        $schema = $this->schemaService->build($category);

        $spreadsheet = new Spreadsheet;

        $productsSheet = $spreadsheet->getActiveSheet();
        $productsSheet->setTitle(CategoryFilterSchemaService::PRODUCTS_SHEET);

        $this->writeProductsHeader($productsSheet, $schema['columns']);

        $lastDataRow = $this->writeProductRows($productsSheet, $category, $schema);

        $referencesSheet = $spreadsheet->createSheet();
        $referencesSheet->setTitle(CategoryFilterSchemaService::REFERENCES_SHEET);

        $validationRanges = $this->writeReferences($referencesSheet, $schema);

        $this->applyDataValidation($productsSheet, $schema['columns'], $schema['attributes_by_key'], $validationRanges, $lastDataRow);

        $metaSheet = $spreadsheet->createSheet();
        $metaSheet->setTitle(CategoryFilterSchemaService::META_SHEET);
        $metaSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $this->writeMeta($metaSheet, $schema);

        $this->autoSizeColumns($productsSheet);
        $this->autoSizeColumns($referencesSheet);
        $this->autoSizeColumns($metaSheet);

        $downloadName = 'category-filter-template-'.$category->getKey().'-'.now()->format('Ymd-His').'.xlsx';
        $dir = Storage::disk('local')->path('exports');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.$downloadName;

        (new Xlsx($spreadsheet))->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'path' => $path,
            'downloadName' => $downloadName,
            'schema_hash' => (string) $schema['schema_hash'],
            'category_id' => (int) $schema['category_id'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     */
    protected function writeProductsHeader(Worksheet $sheet, array $columns): void
    {
        foreach (array_values($columns) as $index => $column) {
            $columnNumber = $index + 1;

            $sheet->setCellValue([$columnNumber, 1], (string) $column['key']);
            $sheet->setCellValue([$columnNumber, 2], (string) $column['label']);
        }

        $lastColumn = $sheet->getHighestColumn();

        $sheet->getStyle("A1:{$lastColumn}1")
            ->getFont()
            ->setBold(true);

        $sheet->getStyle("A2:{$lastColumn}2")
            ->getFont()
            ->setBold(true);

        $sheet->freezePane('A3');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function writeProductRows(Worksheet $sheet, Category $category, array $schema): int
    {
        $attributeIds = collect($schema['attributes'])
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $optionAttributeIds = collect($schema['attributes'])
            ->filter(fn (array $attribute): bool => in_array($attribute['template_type'], ['select', 'multiselect'], true))
            ->pluck('attribute_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $products = Product::query()
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
            ->orderBy('id')
            ->get(['id', 'name', 'sku', 'updated_at']);

        $rowNumber = 3;

        foreach ($products as $product) {
            $attributeValues = $product->attributeValues->keyBy('attribute_id');
            $attributeOptions = $product->attributeOptions
                ->groupBy(fn ($option): int => (int) ($option->pivot->attribute_id ?? 0));

            foreach (array_values($schema['columns']) as $columnIndex => $column) {
                $columnNumber = $columnIndex + 1;
                $value = $this->resolveCellValue(
                    product: $product,
                    column: $column,
                    attributesByKey: $schema['attributes_by_key'],
                    attributeValues: $attributeValues,
                    attributeOptions: $attributeOptions,
                );

                if (($column['key'] ?? null) === 'updated_at' && $value !== null) {
                    $sheet
                        ->getCell([$columnNumber, $rowNumber])
                        ->setValueExplicit((string) $value, DataType::TYPE_STRING);

                    continue;
                }

                $sheet->setCellValue([$columnNumber, $rowNumber], $value);
            }

            $rowNumber++;
        }

        return max(3, $rowNumber - 1);
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, array<string, mixed>>  $attributesByKey
     */
    protected function resolveCellValue(
        Product $product,
        array $column,
        array $attributesByKey,
        Collection $attributeValues,
        Collection $attributeOptions,
    ): mixed {
        if (($column['kind'] ?? null) === 'fixed') {
            return match ($column['key']) {
                'product_id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku ? (string) $product->sku : null,
                'updated_at' => optional($product->updated_at)->format('Y-m-d H:i:s'),
                default => null,
            };
        }

        $attributeKey = (string) ($column['attribute_key'] ?? '');
        $attribute = $attributesByKey[$attributeKey] ?? null;

        if (! $attribute) {
            return null;
        }

        $attributeId = (int) ($attribute['attribute_id'] ?? 0);
        /** @var ProductAttributeValue|null $value */
        $value = $attributeValues->get($attributeId);

        /** @var Collection<int, mixed> $optionRows */
        $optionRows = $attributeOptions->get($attributeId, collect());

        $templateType = (string) ($attribute['template_type'] ?? 'text');
        $valueMode = (string) ($column['value_mode'] ?? 'single');

        if ($templateType === 'select') {
            return $optionRows->first()?->value;
        }

        if ($templateType === 'multiselect') {
            return $optionRows
                ->pluck('value')
                ->map(fn ($label) => trim((string) $label))
                ->filter(fn ($label) => $label !== '')
                ->implode(';');
        }

        if ($templateType === 'boolean') {
            if (! $value || $value->value_boolean === null) {
                return null;
            }

            return $value->value_boolean ? 'Да' : 'Нет';
        }

        if ($templateType === 'text') {
            if (! $value || $value->value_text === null) {
                return null;
            }

            $text = trim((string) $value->value_text);

            return $text !== '' ? $text : null;
        }

        if ($templateType === 'number') {
            return $this->numberToUi($value, $attribute);
        }

        if ($templateType === 'range') {
            if ($valueMode === 'range_min') {
                return $this->rangeBoundaryToUi($value, $attribute, 'min');
            }

            if ($valueMode === 'range_max') {
                return $this->rangeBoundaryToUi($value, $attribute, 'max');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function numberToUi(?ProductAttributeValue $value, array $attribute): ?float
    {
        if (! $value) {
            return null;
        }

        $si = $value->value_si;

        if ($si === null && $value->value_number !== null) {
            $si = $this->baseToSi((float) $value->value_number, $attribute);
        }

        if ($si === null) {
            return null;
        }

        $ui = $this->siToDisplayUi((float) $si, $attribute);

        return $ui === null ? null : $this->quantize($ui, $attribute);
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function rangeBoundaryToUi(?ProductAttributeValue $value, array $attribute, string $boundary): ?float
    {
        if (! $value) {
            return null;
        }

        $si = $boundary === 'min' ? $value->value_min_si : $value->value_max_si;

        if ($si === null) {
            $base = $boundary === 'min' ? $value->value_min : $value->value_max;

            if ($base !== null) {
                $si = $this->baseToSi((float) $base, $attribute);
            }
        }

        if ($si === null) {
            return null;
        }

        $ui = $this->siToDisplayUi((float) $si, $attribute);

        return $ui === null ? null : $this->quantize($ui, $attribute);
    }

    /**
     * @param  array<string, mixed>  $attribute
     */
    protected function baseToSi(float $base, array $attribute): ?float
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
    protected function siToDisplayUi(float $si, array $attribute): ?float
    {
        $displayUnit = $attribute['display_unit'] ?? null;

        if (! is_array($displayUnit)) {
            $displayUnit = $attribute['base_unit'] ?? null;
        }

        if (! is_array($displayUnit)) {
            return $si;
        }

        $factor = (float) ($displayUnit['si_factor'] ?? 1.0);
        $offset = (float) ($displayUnit['si_offset'] ?? 0.0);

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

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array{formula:string,last_row:int,type:string}>
     */
    protected function writeReferences(Worksheet $sheet, array $schema): array
    {
        $columnNumber = 1;
        $validationRanges = [];

        foreach ($schema['attributes'] as $attribute) {
            $templateType = (string) ($attribute['template_type'] ?? '');

            if (! in_array($templateType, ['select', 'multiselect', 'boolean'], true)) {
                continue;
            }

            $columnKey = (string) ($attribute['attribute_key'] ?? '');
            $columnLetter = Coordinate::stringFromColumnIndex($columnNumber);

            $sheet->setCellValue([$columnNumber, 1], $columnKey);

            $values = $templateType === 'boolean'
                ? ['Да', 'Нет']
                : collect($attribute['options'] ?? [])
                    ->pluck('label')
                    ->map(fn ($label) => trim((string) $label))
                    ->filter(fn ($label) => $label !== '')
                    ->values()
                    ->all();

            $rowNumber = 2;
            foreach ($values as $value) {
                $sheet->setCellValue([$columnNumber, $rowNumber], $value);
                $rowNumber++;
            }

            $lastRow = max(2, $rowNumber - 1);

            $validationRanges[$columnKey] = [
                'formula' => "'".CategoryFilterSchemaService::REFERENCES_SHEET."'!$".$columnLetter.'$2:$'.$columnLetter.'$'.$lastRow,
                'last_row' => $lastRow,
                'type' => $templateType,
            ];

            $columnNumber++;
        }

        if ($columnNumber > 1) {
            $lastColumn = Coordinate::stringFromColumnIndex($columnNumber - 1);
            $sheet->getStyle("A1:{$lastColumn}1")
                ->getFont()
                ->setBold(true);
        }

        return $validationRanges;
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  array<string, array<string, mixed>>  $attributesByKey
     * @param  array<string, array{formula:string,last_row:int,type:string}>  $validationRanges
     */
    protected function applyDataValidation(
        Worksheet $productsSheet,
        array $columns,
        array $attributesByKey,
        array $validationRanges,
        int $lastDataRow,
    ): void {
        foreach (array_values($columns) as $index => $column) {
            if (($column['kind'] ?? null) !== 'attribute') {
                continue;
            }

            $attributeKey = (string) ($column['attribute_key'] ?? '');
            $attribute = $attributesByKey[$attributeKey] ?? null;

            if (! $attribute) {
                continue;
            }

            $templateType = (string) ($attribute['template_type'] ?? '');

            if (! in_array($templateType, ['select', 'boolean'], true)) {
                continue;
            }

            $validation = $validationRanges[$attributeKey] ?? null;

            if (! $validation) {
                continue;
            }

            $columnNumber = $index + 1;
            $fromRow = 3;
            $toRow = max(3, $lastDataRow);

            for ($row = $fromRow; $row <= $toRow; $row++) {
                $cellValidation = $productsSheet->getCell([$columnNumber, $row])->getDataValidation();
                $cellValidation->setType(DataValidation::TYPE_LIST);
                $cellValidation->setErrorStyle(DataValidation::STYLE_STOP);
                $cellValidation->setAllowBlank(true);
                $cellValidation->setShowInputMessage(true);
                $cellValidation->setShowErrorMessage(true);
                $cellValidation->setShowDropDown(true);
                $cellValidation->setErrorTitle('Недопустимое значение');
                $cellValidation->setError('Выберите значение из справочника.');
                $cellValidation->setFormula1($validation['formula']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function writeMeta(Worksheet $sheet, array $schema): void
    {
        $rows = [
            ['key', 'value'],
            ['template_type', (string) $schema['template_type']],
            ['category_id', (string) $schema['category_id']],
            ['schema_hash', (string) $schema['schema_hash']],
            ['clear_marker', CategoryFilterSchemaService::CLEAR_MARKER],
            ['exported_at', now()->format('Y-m-d H:i:s')],
        ];

        foreach ($rows as $rowIndex => $row) {
            $sheet->setCellValue([1, $rowIndex + 1], $row[0]);
            $sheet->setCellValue([2, $rowIndex + 1], $row[1]);
        }

        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
    }

    protected function autoSizeColumns(Worksheet $sheet): void
    {
        $lastColumn = $sheet->getHighestColumn();
        $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);

        for ($column = 1; $column <= $lastColumnIndex; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
    }
}
