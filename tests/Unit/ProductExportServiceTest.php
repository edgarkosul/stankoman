<?php

use App\Models\Product;
use App\Support\Products\ProductExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection as CellProtection;
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
        $table->decimal('wholesale_price', 14, 4)->nullable();
        $table->char('wholesale_currency', 3)->nullable();
        $table->decimal('exchange_rate', 14, 6)->nullable();
        $table->boolean('auto_update_exchange_rate')->default(false);
        $table->decimal('wholesale_price_rub', 14, 2)->nullable();
        $table->decimal('markup_multiplier', 8, 4)->nullable();
        $table->decimal('margin_amount_rub', 14, 2)->nullable();
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

it('exports enum-casted warranty field as scalar value', function () {
    Product::query()->create([
        'name' => 'Warranty Tool',
        'sku' => 'WARRANTY-1',
        'warranty' => '24',
    ]);

    $service = new ProductExportService;
    $result = $service->exportToXlsx(Product::query(), ['warranty']);

    expect($result['path'])->toBeFile();

    $spreadsheet = IOFactory::createReader('Xlsx')->load($result['path']);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('Наименование');
    expect($sheet->getCell('B1')->getValue())->toBe('Гарантия');
    expect((string) $sheet->getCell('B2')->getValue())->toBe('24');

    $spreadsheet->disconnectWorksheets();
    unlink($result['path']);
});

it('exports pricing parameters with site price from price amount', function () {
    Product::query()->create([
        'name' => 'Pricing Export Tool',
        'wholesale_price' => '100.5000',
        'wholesale_currency' => 'USD',
        'exchange_rate' => '90.000000',
        'wholesale_price_rub' => '9045.00',
        'markup_multiplier' => '1.2000',
        'price_amount' => 10854,
        'margin_amount_rub' => '1809.00',
        'discount_price' => 9769,
    ]);

    $service = new ProductExportService;
    $result = $service->exportToXlsx(Product::query(), [
        'wholesale_price',
        'wholesale_currency',
        'exchange_rate',
        'wholesale_price_rub',
        'markup_multiplier',
        'price_amount',
        'discount_percent',
        'discount_price',
    ]);

    expect($result['path'])->toBeFile();

    $spreadsheet = IOFactory::createReader('Xlsx')->load($result['path']);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('B1')->getValue())->toBe('Цена опт');
    expect($sheet->getCell('C1')->getValue())->toBe('Валюта');
    expect($sheet->getCell('D1')->getValue())->toBe('Курс валюты');
    expect($sheet->getCell('E1')->getValue())->toBe('Опт, руб');
    expect($sheet->getCell('F1')->getValue())->toBe('Наценка');
    expect($sheet->getCell('G1')->getValue())->toBe('Цена на сайт, руб');
    expect($sheet->getCell('H1')->getValue())->toBe('Скидка в %');
    expect($sheet->getCell('I1')->getValue())->toBe('Цена со скидкой');

    expect((float) $sheet->getCell('B2')->getValue())->toBe(100.5);
    expect((string) $sheet->getCell('C2')->getValue())->toBe('USD');
    expect((float) $sheet->getCell('D2')->getValue())->toBe(90.0);
    expect((float) $sheet->getCell('E2')->getValue())->toBe(9045.0);
    expect((float) $sheet->getCell('F2')->getValue())->toBe(1.2);
    expect((int) $sheet->getCell('G2')->getValue())->toBe(10854);
    expect((float) $sheet->getCell('H2')->getValue())->toBe(10.0);
    expect((int) $sheet->getCell('I2')->getValue())->toBe(9769);
    expect($sheet->getStyle('A2')->getNumberFormat()->getFormatCode())->toBe(NumberFormat::FORMAT_TEXT);
    expect($sheet->getStyle('B2')->getNumberFormat()->getFormatCode())->toBe('0.0000');
    expect($sheet->getStyle('C2')->getNumberFormat()->getFormatCode())->toBe(NumberFormat::FORMAT_TEXT);
    expect($sheet->getStyle('D2')->getNumberFormat()->getFormatCode())->toBe('0.00');
    expect($sheet->getStyle('E2')->getNumberFormat()->getFormatCode())->toBe(NumberFormat::FORMAT_NUMBER);
    expect($sheet->getStyle('F2')->getNumberFormat()->getFormatCode())->toBe('0.00');
    expect($sheet->getStyle('G2')->getNumberFormat()->getFormatCode())->toBe(NumberFormat::FORMAT_NUMBER);
    expect($sheet->getStyle('H2')->getNumberFormat()->getFormatCode())->toBe('0.00');
    expect($sheet->getStyle('I2')->getNumberFormat()->getFormatCode())->toBe(NumberFormat::FORMAT_NUMBER);
    expect($sheet->getStyle('J2')->getNumberFormat()->getFormatCode())->toBe(NumberFormat::FORMAT_TEXT);

    $spreadsheet->disconnectWorksheets();
    unlink($result['path']);
});

it('adds dropdown validation for allowed wholesale currencies', function () {
    Product::query()->create([
        'name' => 'Currency Validation Tool',
        'wholesale_currency' => 'USD',
    ]);

    $service = new ProductExportService;
    $result = $service->exportToXlsx(Product::query(), ['wholesale_currency']);

    expect($result['path'])->toBeFile();

    $spreadsheet = IOFactory::createReader('Xlsx')->load($result['path']);
    $sheet = $spreadsheet->getActiveSheet();
    $validation = $sheet->getCell('B2')->getDataValidation();

    expect($sheet->getCell('B1')->getValue())->toBe('Валюта')
        ->and($validation->getType())->toBe(DataValidation::TYPE_LIST)
        ->and($validation->getFormula1())->toBe('"USD,CNY,EUR,RUR"');

    $spreadsheet->disconnectWorksheets();
    unlink($result['path']);
});

it('exports boolean columns as ИСТИНА/ЛОЖЬ with dropdown validation', function () {
    Product::query()->create([
        'name' => 'Boolean Tool',
        'in_stock' => true,
        'is_active' => false,
    ]);

    $service = new ProductExportService;
    $result = $service->exportToXlsx(Product::query(), ['in_stock', 'is_active']);

    expect($result['path'])->toBeFile();

    $spreadsheet = IOFactory::createReader('Xlsx')->load($result['path']);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('B1')->getValue())->toBe('В наличии');
    expect($sheet->getCell('C1')->getValue())->toBe('Показывать на сайте');
    expect((string) $sheet->getCell('B2')->getValue())->toBe('ИСТИНА');
    expect((string) $sheet->getCell('C2')->getValue())->toBe('ЛОЖЬ');

    $b2Validation = $sheet->getCell('B2')->getDataValidation();
    $c2Validation = $sheet->getCell('C2')->getDataValidation();

    expect($b2Validation->getType())->toBe(DataValidation::TYPE_LIST);
    expect($b2Validation->getFormula1())->toBe('"ИСТИНА,ЛОЖЬ"');
    expect($c2Validation->getType())->toBe(DataValidation::TYPE_LIST);
    expect($c2Validation->getFormula1())->toBe('"ИСТИНА,ЛОЖЬ"');

    $spreadsheet->disconnectWorksheets();
    unlink($result['path']);
});

it('locks service columns and keeps other columns editable', function () {
    Product::query()->create([
        'name' => 'Locked Service Columns Product',
        'sku' => 'LOCK-1',
        'is_active' => true,
    ]);

    $service = new ProductExportService;
    $result = $service->exportToXlsx(Product::query(), ['sku', 'is_active']);

    expect($result['path'])->toBeFile();

    $spreadsheet = IOFactory::createReader('Xlsx')->load($result['path']);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getProtection()->getSheet())->toBeTrue();
    expect($sheet->getProtection()->getFormatColumns())->toBeFalse();
    expect($sheet->getStyle('A2')->getProtection()->getLocked())->toBe(CellProtection::PROTECTION_PROTECTED);
    expect($sheet->getStyle('B2')->getProtection()->getLocked())->toBe(CellProtection::PROTECTION_UNPROTECTED);
    expect($sheet->getStyle('C2')->getProtection()->getLocked())->toBe(CellProtection::PROTECTION_UNPROTECTED);
    expect($sheet->getStyle('D2')->getProtection()->getLocked())->toBe(CellProtection::PROTECTION_PROTECTED);

    $spreadsheet->disconnectWorksheets();
    unlink($result['path']);
});
