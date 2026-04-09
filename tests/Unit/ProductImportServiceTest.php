<?php

use App\Models\ImportRun;
use App\Models\Product;
use App\Support\NameNormalizer;
use App\Support\Products\ProductImportService;
use App\Support\Products\ProductSearchSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    Schema::dropIfExists('import_issues');
    Schema::dropIfExists('import_runs');
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

    Schema::create('import_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('type')->default('products');
        $table->string('status')->default('pending');
        $table->json('columns')->nullable();
        $table->json('totals')->nullable();
        $table->string('source_filename')->nullable();
        $table->string('stored_path')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });

    Schema::create('import_issues', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('run_id');
        $table->integer('row_index')->nullable();
        $table->string('code', 64);
        $table->string('severity', 16)->default('error');
        $table->text('message')->nullable();
        $table->json('row_snapshot')->nullable();
        $table->timestamps();
    });

    config()->set('catalog-export.staging_category_slug', null);
});

function makeProductsImportXlsx(array $headers, array $rows): string
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

    $path = storage_path('framework/testing/import-'.Str::uuid().'.xlsx');
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

it('builds dry-run summary for existing product update', function () {
    $product = Product::query()->create([
        'name' => 'Bosch GSR 120-LI',
        'sku' => 'GSR-120',
        'brand' => 'Bosch',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'new_name', 'sku', 'brand', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        '',
        'GSR-120',
        'Bosch Professional',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;
    $result = $service->dryRunFromXlsx($run, $path);

    expect($result['totals'])->toMatchArray([
        'create' => 0,
        'update' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($run->fresh()->status)->toBe('dry_run');
    expect($run->fresh()->columns)->toBe($headers);
    expect($result['preview']['update'])->toHaveCount(1);
    expect($result['preview']['update'][0]['id'])->toBe($product->id);

    unlink($path);
});

it('counts rename-only row as update in dry-run summary', function () {
    $product = Product::query()->create([
        'name' => 'Rename Source Product',
        'sku' => 'REN-1',
        'brand' => 'Brand',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'new_name', 'sku', 'brand', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        'Rename Target Product',
        'REN-1',
        'Brand',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;
    $result = $service->dryRunFromXlsx($run, $path);

    expect($result['totals'])->toMatchArray([
        'create' => 0,
        'update' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($result['preview']['update'])->toHaveCount(1);
    expect($result['preview']['update'][0])->toMatchArray([
        'id' => $product->id,
        'name' => 'Rename Source Product',
        'new_name' => 'Rename Target Product',
    ]);

    unlink($path);
});

it('applies rename and field update by name_normalized key', function () {
    $product = Product::query()->create([
        'name' => 'Old Product Name',
        'sku' => 'OLD-1',
        'brand' => 'Old Brand',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'new_name', 'sku', 'brand', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        'New Product Name',
        'OLD-1',
        'New Brand',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;
    $service->dryRunFromXlsx($run, $path);
    $result = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($result)->toMatchArray([
        'created' => 0,
        'updated' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    $product->refresh();

    expect($product->name)->toBe('New Product Name');
    expect($product->brand)->toBe('New Brand');
    expect($product->name_normalized)->toBe(NameNormalizer::normalize('New Product Name'));
    expect($run->fresh()->status)->toBe('applied');

    unlink($path);
});

it('syncs updated products to search index after apply', function () {
    $product = Product::query()->create([
        'name' => 'Search Sync Product',
        'sku' => 'SYNC-1',
        'brand' => 'Old Brand',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'brand', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        'Updated Brand',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $searchSync = Mockery::mock(ProductSearchSync::class);
    $searchSync->shouldReceive('syncIds')
        ->once()
        ->with([$product->id])
        ->andReturn([
            'synced' => 1,
            'removed' => 0,
        ]);

    $service = new ProductImportService($searchSync);
    $service->dryRunFromXlsx($run, $path);
    $result = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($result['updated'])->toBe(1);
    expect($product->fresh()->brand)->toBe('Updated Brand');

    unlink($path);
});

it('creates new product in safe defaults mode on apply', function () {
    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'new_name', 'sku', 'brand', 'is_active', 'in_stock', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        'Brand New Generator',
        '',
        'GEN-5000',
        'Stankoman',
        1,
        0,
        '',
    ]]);

    $service = new ProductImportService;
    $service->dryRunFromXlsx($run, $path);
    $result = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($result)->toMatchArray([
        'created' => 1,
        'updated' => 0,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    $created = Product::query()->where('name', 'Brand New Generator')->first();

    expect($created)->not->toBeNull();
    expect($created->name_normalized)->toBe(NameNormalizer::normalize('Brand New Generator'));
    expect($created->is_active)->toBeFalse();
    expect($created->in_stock)->toBeTrue();
    expect($run->fresh()->status)->toBe('applied');

    unlink($path);
});

it('applies ИСТИНА/ЛОЖЬ literals to boolean fields', function () {
    $product = Product::query()->create([
        'name' => 'Boolean Import Product',
        'in_stock' => true,
        'is_active' => false,
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'is_active', 'in_stock', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        'ИСТИНА',
        'ЛОЖЬ',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;

    $dryRun = $service->dryRunFromXlsx($run, $path);
    $apply = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($dryRun['totals'])->toMatchArray([
        'create' => 0,
        'update' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($apply)->toMatchArray([
        'created' => 0,
        'updated' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    $product->refresh();

    expect($product->is_active)->toBeTrue();
    expect($product->in_stock)->toBeFalse();

    unlink($path);
});

it('handles enum-casted warranty field during dry-run and apply', function () {
    $product = Product::query()->create([
        'name' => 'Warranty Import Product',
        'sku' => 'WIP-1',
        'brand' => 'Brand',
        'warranty' => '12',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'warranty', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        '24',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;

    $dryRun = $service->dryRunFromXlsx($run, $path);
    $apply = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($dryRun['totals'])->toMatchArray([
        'create' => 0,
        'update' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($apply)->toMatchArray([
        'created' => 0,
        'updated' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($product->fresh()->warranty?->value)->toBe('24');

    unlink($path);
});

it('accepts human-readable warranty labels during dry-run and apply', function () {
    $product = Product::query()->create([
        'name' => 'Warranty Label Import Product',
        'sku' => 'WIP-LABEL-1',
        'brand' => 'Brand',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'warranty', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        '12 мес.',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;

    $dryRun = $service->dryRunFromXlsx($run, $path);
    $apply = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($dryRun['totals'])->toMatchArray([
        'create' => 0,
        'update' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($apply)->toMatchArray([
        'created' => 0,
        'updated' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($product->fresh()->warranty?->value)->toBe('12');

    unlink($path);
});

it('rejects unknown warranty labels during dry-run and apply', function () {
    $product = Product::query()->create([
        'name' => 'Invalid Warranty Label Product',
        'sku' => 'WIP-LABEL-INVALID-1',
        'brand' => 'Brand',
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'warranty', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        '6 мес.',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;

    $dryRun = $service->dryRunFromXlsx($run, $path);
    $apply = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($dryRun['totals'])->toMatchArray([
        'create' => 0,
        'update' => 0,
        'same' => 0,
        'conflict' => 0,
        'error' => 1,
        'scanned' => 1,
    ]);

    expect($apply)->toMatchArray([
        'created' => 0,
        'updated' => 0,
        'same' => 0,
        'conflict' => 0,
        'error' => 1,
        'scanned' => 1,
    ]);

    expect($product->fresh()->warranty)->toBeNull()
        ->and($run->fresh()->issues()->latest('id')->value('message'))->toBe('Invalid value for warranty: 6 мес.');

    unlink($path);
});

it('imports pricing parameters and recalculates site price and margin', function () {
    $product = Product::query()->create([
        'name' => 'Pricing Import Product',
        'sku' => 'PRICE-1',
        'price_amount' => 1000,
    ]);

    $run = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $headers = ['name', 'wholesale_price', 'wholesale_currency', 'exchange_rate', 'markup_multiplier', 'updated_at'];
    $path = makeProductsImportXlsx($headers, [[
        $product->name,
        '100,5',
        'USD',
        '90',
        '1,2',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service = new ProductImportService;

    $dryRun = $service->dryRunFromXlsx($run, $path);
    $apply = $service->applyFromXlsx($run->fresh(), $path, ['write' => true]);

    expect($dryRun['totals'])->toMatchArray([
        'create' => 0,
        'update' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    expect($apply)->toMatchArray([
        'created' => 0,
        'updated' => 1,
        'same' => 0,
        'conflict' => 0,
        'error' => 0,
        'scanned' => 1,
    ]);

    $product->refresh();

    expect($product->wholesale_price)->toBe('100.5000')
        ->and($product->wholesale_currency)->toBe('USD')
        ->and($product->exchange_rate)->toBe('90.00')
        ->and($product->wholesale_price_rub)->toBe('9045')
        ->and($product->markup_multiplier)->toBe('1.20')
        ->and($product->price_amount)->toBe(10854)
        ->and($product->margin_amount_rub)->toBe('1809.00');

    unlink($path);
});

it('accepts CHY alias for CNY and rejects unsupported wholesale currencies', function () {
    $product = Product::query()->create([
        'name' => 'Currency Import Product',
        'sku' => 'CUR-1',
        'price_amount' => 1000,
    ]);

    $service = new ProductImportService;

    $runWithAlias = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $pathWithAlias = makeProductsImportXlsx(['name', 'wholesale_currency', 'updated_at'], [[
        $product->name,
        'CHY',
        $product->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $service->dryRunFromXlsx($runWithAlias, $pathWithAlias);
    $applyWithAlias = $service->applyFromXlsx($runWithAlias->fresh(), $pathWithAlias, ['write' => true]);

    expect($applyWithAlias['error'])->toBe(0)
        ->and($product->fresh()->wholesale_currency)->toBe('CNY');

    unlink($pathWithAlias);

    $runWithInvalid = ImportRun::query()->create([
        'type' => 'products',
        'status' => 'pending',
    ]);

    $pathWithInvalid = makeProductsImportXlsx(['name', 'wholesale_currency', 'updated_at'], [[
        $product->name,
        'GBP',
        $product->fresh()->updated_at->format('Y-m-d H:i:s'),
    ]]);

    $dryRunWithInvalid = $service->dryRunFromXlsx($runWithInvalid, $pathWithInvalid);
    $applyWithInvalid = $service->applyFromXlsx($runWithInvalid->fresh(), $pathWithInvalid, ['write' => true]);

    expect($dryRunWithInvalid['totals']['error'])->toBe(1)
        ->and($applyWithInvalid['error'])->toBe(1)
        ->and($product->fresh()->wholesale_currency)->toBe('CNY');

    unlink($pathWithInvalid);
});
