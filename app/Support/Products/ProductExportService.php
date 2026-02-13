<?php

namespace App\Support\Products;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductExportService
{
    public function availableFields(): array
    {
        return config('catalog-export.fields');
    }

    public function forcedColumns(): array
    {
        return config('catalog-export.forced_columns', []);
    }

    public function defaultColumns(): array
    {
        return config('catalog-export.default_export', []);
    }

    public function validateColumns(?array $requested): array
    {
        $fields = $this->availableFields();          // config('catalog-export.fields')
        $forced = $this->forcedColumns();            // ['name', 'updated_at']

        // Если пользователь ничего не выбрал — используем default_export
        $requested = $requested ?: $this->defaultColumns();

        // 1) Собираем "супермножество": выбор пользователя + принудительные колонки
        $superset = array_values(array_unique(array_merge(
            $requested,
            $forced
        )));

        // 2) Фильтруем только известные поля из whitelist
        $superset = array_values(array_filter(
            $superset,
            fn ($c) => isset($fields[$c])
        ));

        // 3) Убираем принудительные колонки из середины — будем расставлять руками
        $middle = array_values(array_filter(
            $superset,
            fn ($c) => ! in_array($c, $forced, true)
        ));

        $columns = [];

        // --- name всегда первым ---
        if (in_array('name', $forced, true) && isset($fields['name'])) {
            $columns[] = 'name';
        }

        // --- все остальные выбранные пользователем (кроме forced) ---
        foreach ($middle as $col) {
            if (! in_array($col, $columns, true)) {
                $columns[] = $col;
            }
        }

        // --- updated_at всегда последним ---
        if (in_array('updated_at', $forced, true) && isset($fields['updated_at']) && ! in_array('updated_at', $columns, true)) {
            $columns[] = 'updated_at';
        }
        // На всякий случай добавляем недостающие принудительные колонки
        foreach ($forced as $c) {
            if (! in_array($c, $columns, true) && isset($fields[$c])) {
                $columns[] = $c;
            }
        }

        return $columns;
    }

    public function buildDataset(Builder $query, array $columns): array
    {
        $fields = $this->availableFields();

        // Человеко-понятные заголовки (RU) для XLSX
        $headers = [];
        foreach ($columns as $key) {
            $headers[] = $fields[$key]['header'] ?? $key;
        }

        $rows = $query
            ->select($this->selectColumnsSubset($columns))
            ->orderBy('id')
            ->get()
            ->map(function ($product) use ($columns) {
                $row = [];
                foreach ($columns as $col) {
                    $row[$col] = $this->extractCellValue($product, $col);
                }

                return $row;
            })
            ->all();

        return [
            'headers' => $headers,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    protected function selectColumnsSubset(array $columns): array
    {
        $base = ['id', 'name', 'updated_at'];
        $fields = $this->availableFields();

        // Любые virtual-поля (в т.ч. new_name) не должны попадать в SELECT
        $virtual = [];
        foreach ($columns as $col) {
            if (! empty($fields[$col]['virtual'])) {
                $virtual[] = $col;
            }
        }

        $dbColumns = array_values(array_diff($columns, $virtual));

        return array_values(
            array_unique(
                array_merge($base, $dbColumns)
            )
        );
    }

    protected function extractCellValue($product, string $col)
    {
        if ($col === 'new_name') {
            return null;
        }
        if ($col === 'updated_at') {
            return optional($product->updated_at)->format('Y-m-d H:i:s');
        }

        return $product->{$col} ?? null;
    }

    /**
     * Сгенерировать XLSX и сохранить на диске "local".
     * Возвращает ['path' => '/abs/path', 'downloadName' => 'products-YYYYmmdd-HHmmss.xlsx'].
     */
    public function exportToXlsx(Builder $query, array $columns): array
    {
        $columns = $this->validateColumns($columns);
        $dataset = $this->buildDataset($query, $columns);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Шапка
        foreach (array_values($dataset['headers']) as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }
        $sheet->freezePane('A2');

        // Данные
        $rowNum = 2;
        foreach ($dataset['rows'] as $row) {
            $colNum = 1;
            foreach ($dataset['columns'] as $columnKey) {
                if ($columnKey === 'new_name') {
                    $value = null;
                } else {
                    $value = $row[$columnKey] ?? null;
                }
                if ($columnKey === 'updated_at' && $value) {
                    $sheet->getCell([$colNum, $rowNum])->setValueExplicit((string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$colNum, $rowNum], $value);
                }
                $colNum++;
            }
            $rowNum++;
        }

        $lastColumn = $sheet->getHighestColumn(); // напр. 'K'
        $sheet->getStyle("A1:{$lastColumn}1")
            ->getFont()
            ->setBold(true);

        // 2) Автоширина колонок
        $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);
        for ($col = 1; $col <= $lastColumnIndex; $col++) {
            $columnLetter = Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        // (опционально) Автофильтр по шапке
        // $lastRow = $rowNum - 1;
        // $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");

        // Имя и сохранение
        $downloadName = 'products-'.now()->format('Ymd-His').'.xlsx';
        $dir = Storage::disk('local')->path('exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.DIRECTORY_SEPARATOR.$downloadName;

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return ['path' => $path, 'downloadName' => $downloadName];
    }
}
