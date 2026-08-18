<?php

use App\Filament\Pages\ProductImportExport;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeImportFileForPage(array $headers, array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    foreach (array_values($headers) as $columnIndex => $header) {
        $sheet->setCellValue([$columnIndex + 1, 1], $header);
    }

    $rowNumber = 2;
    foreach ($rows as $row) {
        foreach (array_values($row) as $columnIndex => $value) {
            $sheet->setCellValue([$columnIndex + 1, $rowNumber], $value);
        }
        $rowNumber++;
    }

    $path = storage_path('framework/testing/page-import-'.Str::uuid().'.xlsx');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

function makeImportAdmin(): User
{
    config(['settings.general.filament_admin_emails' => ['admin@example.com']]);

    return User::factory()->create(['email' => 'admin@example.com']);
}

test('строки с конфликтом больше не выдаются за успешный импорт', function (): void {
    $admin = makeImportAdmin();

    $product = Product::query()->create([
        'name' => 'Конфликтный товар',
        'slug' => 'conflict-product',
        'price_amount' => 1000,
    ]);

    // Файл сделан до последнего изменения товара — строка попадёт в конфликты.
    $path = makeImportFileForPage(['name', 'sku', 'updated_at'], [[
        $product->name,
        'NEW-SKU',
        $product->updated_at->copy()->subDay()->format('Y-m-d H:i:s'),
    ]]);

    ImportRun::query()->create([
        'type' => 'products',
        'status' => 'dry_run',
        'stored_path' => $path,
        'source_filename' => basename($path),
    ]);

    Livewire::actingAs($admin)
        ->test(ProductImportExport::class)
        ->call('doApply')
        ->assertNotified('Импорт применён частично');

    expect($product->fresh()->sku)->toBeNull();

    unlink($path);
});

test('чистый импорт по-прежнему рапортует успехом', function (): void {
    $admin = makeImportAdmin();

    $product = Product::query()->create([
        'name' => 'Обычный товар',
        'slug' => 'normal-product',
        'price_amount' => 1000,
    ]);

    $path = makeImportFileForPage(['name', 'sku', 'updated_at'], [[
        $product->name,
        'NEW-SKU',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    ImportRun::query()->create([
        'type' => 'products',
        'status' => 'dry_run',
        'stored_path' => $path,
        'source_filename' => basename($path),
    ]);

    Livewire::actingAs($admin)
        ->test(ProductImportExport::class)
        ->call('doApply')
        ->assertNotified('Импорт применён');

    expect($product->fresh()->sku)->toBe('NEW-SKU');

    unlink($path);
});
