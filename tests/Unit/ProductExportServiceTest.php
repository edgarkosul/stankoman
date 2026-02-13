<?php

use App\Models\Product;
use App\Support\Products\ProductExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    Schema::dropIfExists('products');

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->string('sku')->nullable();
        $table->string('brand')->nullable();
        $table->string('country')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->unsignedInteger('qty')->nullable();
        $table->unsignedInteger('popularity')->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->string('warranty')->nullable();
        $table->boolean('with_dns')->default(true);
        $table->text('short')->nullable();
        $table->longText('description')->nullable();
        $table->text('extra_description')->nullable();
        $table->json('specs')->nullable();
        $table->string('promo_info')->nullable();
        $table->string('image')->nullable();
        $table->string('thumb')->nullable();
        $table->json('gallery')->nullable();
        $table->string('meta_title')->nullable();
        $table->text('meta_description')->nullable();
        $table->timestamps();
    });
});

it('forces name first and updated_at last when validating columns', function () {
    $service = new ProductExportService;

    $columns = $service->validateColumns([
        'updated_at',
        'sku',
        'unknown',
        'name',
        'brand',
    ]);

    expect($columns[0])->toBe('name');
    expect($columns[array_key_last($columns)])->toBe('updated_at');
    expect($columns)->toContain('sku', 'brand');
    expect($columns)->not->toContain('unknown');
});

it('exports products to xlsx with configured headers', function () {
    Product::query()->create([
        'name' => 'Alpha Tool',
        'sku' => 'ALPHA-1',
    ]);

    Product::query()->create([
        'name' => 'Beta Tool',
        'sku' => 'BETA-2',
    ]);

    $service = new ProductExportService;
    $result = $service->exportToXlsx(Product::query(), ['sku']);

    expect($result['path'])->toBeFile();

    $spreadsheet = IOFactory::createReader('Xlsx')->load($result['path']);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('Наименование');
    expect($sheet->getCell('B1')->getValue())->toBe('Артикул');
    expect($sheet->getCell('C1')->getValue())->toBe('Изменено');

    expect((string) $sheet->getCell('A2')->getValue())->toBe('Alpha Tool');
    expect((string) $sheet->getCell('B2')->getValue())->toBe('ALPHA-1');

    $spreadsheet->disconnectWorksheets();
    unlink($result['path']);
});
