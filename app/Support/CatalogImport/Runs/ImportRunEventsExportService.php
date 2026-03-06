<?php

namespace App\Support\CatalogImport\Runs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportRunEventsExportService
{
    /**
     * @return array{path: string, downloadName: string}
     */
    public function exportToXlsx(Builder $query, int $runId): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'ID',
            'Этап',
            'Результат',
            'Код',
            'Сообщение',
            'External ID',
            'Product ID',
            'Source Ref',
            'Source Category',
            'Контекст JSON',
            'Создано',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        $sheet->freezePane('A2');

        $row = 2;

        foreach ($query->orderBy('id')->cursor() as $event) {
            $sheet->setCellValueExplicit([1, $row], (string) $event->id, DataType::TYPE_STRING);
            $sheet->setCellValue([2, $row], ImportRunEventLabels::stageLabel($event->stage));
            $sheet->setCellValue([3, $row], ImportRunEventLabels::resultLabel($event->result));
            $sheet->setCellValue([4, $row], (string) ($event->code ?? ''));
            $sheet->setCellValue([5, $row], (string) ($event->message ?? ''));
            $sheet->setCellValue([6, $row], (string) ($event->external_id ?? ''));
            $sheet->setCellValue([7, $row], (string) ($event->product_id ?? ''));
            $sheet->setCellValue([8, $row], (string) ($event->source_ref ?? ''));
            $sheet->setCellValue([9, $row], (string) ($event->source_category_id ?? ''));
            $sheet->setCellValue([10, $row], $this->toJson($event->context));
            $sheet->setCellValue([11, $row], optional($event->created_at)->format('Y-m-d H:i:s'));
            $row++;
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);

        $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);

        for ($column = 1; $column <= $lastColumnIndex; $column++) {
            $columnLetter = Coordinate::stringFromColumnIndex($column);
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        $downloadName = 'import-run-'.$runId.'-events-'.now()->format('Ymd-His').'.xlsx';
        $directory = Storage::disk('local')->path('exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.$downloadName;

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'path' => $path,
            'downloadName' => $downloadName,
        ];
    }

    private function toJson(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
