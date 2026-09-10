<?php

use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductSupplierReference;
use App\Models\Supplier;
use App\Support\CatalogImport\Processing\ExistingProductUpdateSelection;
use App\Support\CatalogImport\Yml\YandexMarketFeedImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('processes yandex market feed in dry-run mode', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="A2" type="vendor.model" available="false">
        <typePrefix>Пылесос</typePrefix>
        <vendor>Vactool</vendor>
        <model>VT-9000</model>
        <price>999</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 2,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(2);
        expect($result['processed'])->toBe(2);
        expect($result['errors'])->toBe(0);
        expect($result['success'])->toBeTrue();
        expect($result['samples'])->toHaveCount(2);
        expect($result['samples'][0]['external_id'])->toBe('A1');
        expect($result['samples'][1]['offer_type'])->toBe('vendor.model');
    } finally {
        @unlink($path);
    }
});

it('defaults currency to rub when currencyId is missing in yandex market feed offers', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <categoryId>1</categoryId>
      </offer>
      <offer id="A2" type="vendor.model" available="false">
        <vendor>Vactool</vendor>
        <model>VT-9000</model>
        <price>999</price>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 2,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(2);
        expect($result['processed'])->toBe(2);
        expect($result['errors'])->toBe(0);
        expect($result['success'])->toBeTrue();
        expect($result['samples'])->toHaveCount(2);
        expect($result['samples'][0]['currency'])->toBe('RUB');
        expect($result['samples'][1]['currency'])->toBe('RUB');
    } finally {
        @unlink($path);
    }
});

it('processes vendor.model offers using name fallback when model is missing', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A2" type="vendor.model" available="true">
        <typePrefix>Компрессоры</typePrefix>
        <vendor>FoxWeld</vendor>
        <name>Компрессор безмасляный AERO 180/0F N10</name>
        <price>8130</price>
        <currencyId>RUB</currencyId>
        <categoryId>137</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 1,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(1);
        expect($result['processed'])->toBe(1);
        expect($result['errors'])->toBe(0);
        expect($result['success'])->toBeTrue();
        expect($result['samples'])->toHaveCount(1);
        expect($result['samples'][0]['name'])->toBe('Компрессор безмасляный AERO 180/0F N10');
        expect($result['samples'][0]['offer_type'])->toBe('vendor.model');
    } finally {
        @unlink($path);
    }
});

it('returns record-level error for invalid yandex market feed offer', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A2" type="vendor.model" available="true">
        <typePrefix>Пылесос</typePrefix>
        <model>VT-9000</model>
        <price>999</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 3,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(1);
        expect($result['processed'])->toBe(0);
        expect($result['errors'])->toBe(1);
        expect($result['success'])->toBeFalse();
        expect($result['url_errors'])->toHaveCount(1);
        expect($result['url_errors'][0]['url'])->toBe('offer:A2');
        expect($result['url_errors'][0]['message'])->toContain('обязательное поле');
    } finally {
        @unlink($path);
    }
});

it('returns non-fatal pdf mapping warnings in url_errors', function () {
    Http::fake([
        'https://example.test/files/invalid.pdf' => Http::response(
            'not found',
            404,
            ['Content-Type' => 'text/plain'],
        ),
    ]);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <param name="Инструкция.pdf">https://example.test/files/invalid.pdf</param>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 1,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['processed'])->toBe(1);
        expect($result['errors'])->toBe(1);
        expect($result['success'])->toBeTrue();
        expect($result['url_errors'])->toHaveCount(1);
        expect($result['url_errors'][0]['url'])->toBe('offer:A1');
        expect($result['url_errors'][0]['message'])->toContain('HTTP 404');
    } finally {
        @unlink($path);
    }
});

it('maps download_images flag into processor download_media option', function () {
    $service = app(YandexMarketFeedImportService::class);

    $normalizeOptions = new ReflectionMethod(YandexMarketFeedImportService::class, 'normalizeOptions');
    $normalizeOptions->setAccessible(true);
    $processorOptions = new ReflectionMethod(YandexMarketFeedImportService::class, 'processorOptions');
    $processorOptions->setAccessible(true);

    $normalized = $normalizeOptions->invoke($service, [
        'source' => 'https://example.test/yml.xml',
        'download_images' => true,
        'run_id' => 77,
        'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
        'update_existing_fields' => [
            ExistingProductUpdateSelection::FIELD_PRICE,
            ExistingProductUpdateSelection::FIELD_IMAGES,
        ],
    ]);
    $options = $processorOptions->invoke($service, $normalized);

    expect($options['download_media'] ?? null)->toBeTrue();
    expect($options['run_id'] ?? null)->toBe(77);
    expect($options['update_existing_mode'] ?? null)->toBe(ExistingProductUpdateSelection::MODE_SELECTED);
    expect($options['update_existing_fields'] ?? null)->toBe([
        ExistingProductUpdateSelection::FIELD_PRICE,
        ExistingProductUpdateSelection::FIELD_IMAGES,
    ]);
});

it('touches numeric prefiltered external ids as strings for yandex feed imports', function () {
    prepareYandexMarketFeedImportServiceReferenceTables();

    $supplier = Supplier::factory()->create([
        'name' => 'Yandex Mixed IDs Supplier',
    ]);
    $run = ImportRun::query()->create([
        'type' => 'yandex_market_feed_products',
        'status' => 'running',
        'supplier_id' => $supplier->id,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'yandex_market_feed',
        'supplier_id' => $supplier->id,
        'external_id' => '595',
        'product_id' => null,
        'last_seen_run_id' => null,
        'last_seen_at' => null,
    ]);
    ProductSupplierReference::query()->create([
        'supplier' => 'yandex_market_feed',
        'supplier_id' => $supplier->id,
        'external_id' => 'product-111',
        'product_id' => null,
        'last_seen_run_id' => null,
        'last_seen_at' => null,
    ]);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-26 13:33">
  <shop>
    <offers>
      <offer id="595" available="true">
        <name>Numeric external id</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'write' => true,
            'skip_existing' => true,
            'supplier_id' => $supplier->id,
            'run_id' => $run->id,
            'delay_ms' => 0,
            'show_samples' => 1,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect(
            ProductSupplierReference::query()
                ->where('supplier_id', $supplier->id)
                ->where('external_id', '595')
                ->value('last_seen_run_id')
        )->toBe($run->id);
        expect(
            ProductSupplierReference::query()
                ->where('supplier_id', $supplier->id)
                ->where('external_id', 'product-111')
                ->value('last_seen_run_id')
        )->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('reads categories from yandex market feed', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <categories>
      <category id="22">Компрессоры</category>
      <category id="31" parentId="22">Пылесосы</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Simple Product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>22</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $categoryNodes = app(YandexMarketFeedImportService::class)->listCategoryNodes([
            'source' => $path,
        ]);
        $categories = app(YandexMarketFeedImportService::class)->listCategories([
            'source' => $path,
        ]);

        expect($categoryNodes)->toBe([
            22 => ['id' => 22, 'name' => 'Компрессоры', 'parent_id' => null],
            31 => ['id' => 31, 'name' => 'Пылесосы', 'parent_id' => 22],
        ]);
        expect($categories)->toBe([
            22 => 'Компрессоры',
            31 => 'Пылесосы',
        ]);
    } finally {
        @unlink($path);
    }
});

it('reports a dry-run plan of created and updated products', function () {
    prepareYandexMarketFeedImportServicePlanTables();

    $supplier = Supplier::factory()->create([
        'name' => 'Yandex Plan Supplier',
    ]);

    $product = Product::query()->create([
        'name' => 'Маска сварщика',
        'slug' => 'maska-svarshchika-plan',
        'price_amount' => 6100,
        'discount_price' => 5490,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
    ]);

    ProductSupplierReference::query()->create([
        'supplier' => 'yandex_market_feed',
        'supplier_id' => $supplier->id,
        'external_id' => 'A1',
        'product_id' => $product->id,
        'last_seen_at' => now(),
    ]);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <offers>
      <offer id="A1" available="true">
        <name>Маска сварщика</name>
        <price>6710</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
      <offer id="A2" available="true">
        <name>Новый товар</name>
        <price>1000</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_plan_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'supplier_id' => $supplier->id,
            'write' => false,
            'delay_ms' => 0,
            'show_samples' => 2,
            'update_existing_mode' => ExistingProductUpdateSelection::MODE_SELECTED,
            'update_existing_fields' => [ExistingProductUpdateSelection::FIELD_PRICE],
        ]);

        expect($result['write_mode'])->toBeFalse()
            ->and($result['processed'])->toBe(2)
            ->and($result['created'])->toBe(1)
            ->and($result['updated'])->toBe(1)
            ->and($result['skipped'])->toBe(0)
            ->and($result['errors'])->toBe(0);

        expect($result['samples'][0]['external_id'])->toBe('A1')
            ->and($result['samples'][0]['plan'])->toBe('Будет обновлен')
            ->and($result['samples'][0]['changes'])->toBe('Цена: 6100 → 6710; Цена со скидкой: 5490 → 6039')
            ->and($result['samples'][1]['plan'])->toBe('Будет создан');

        // dry-run остается предпросмотром: каталог не меняется.
        $product->refresh();

        expect($product->price_amount)->toBe(6100)
            ->and($product->discount_price)->toBe(5490)
            ->and(Product::query()->count())->toBe(1);
    } finally {
        @unlink($path);
    }
});

function prepareYandexMarketFeedImportServicePlanTables(): void
{
    prepareYandexMarketFeedImportServiceReferenceTables();

    if (Schema::hasTable('products')) {
        return;
    }

    ensureProductCategoryTablesExist();

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
        $table->decimal('markup_multiplier', 8, 4)->nullable();
        $table->boolean('in_stock')->default(false);
        $table->unsignedInteger('qty')->nullable();
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->boolean('with_dns')->default(true);
        $table->string('promo_info')->nullable();
        $table->text('short')->nullable();
        $table->longText('description')->nullable();
        $table->longText('extra_description')->nullable();
        $table->longText('instructions')->nullable();
        $table->longText('video')->nullable();
        $table->json('specs')->nullable();
        $table->string('image')->nullable();
        $table->string('thumb')->nullable();
        $table->json('gallery')->nullable();
        $table->string('meta_title')->nullable();
        $table->text('meta_description')->nullable();
        $table->timestamps();
    });
}

function prepareYandexMarketFeedImportServiceReferenceTables(): void
{
    if (! Schema::hasTable('suppliers')) {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('import_runs')) {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('status');
            $table->json('columns')->nullable();
            $table->json('totals')->nullable();
            $table->string('source_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('supplier_import_source_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('product_supplier_references')) {
        Schema::create('product_supplier_references', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('external_id');
            $table->unsignedBigInteger('source_category_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('first_seen_run_id')->nullable();
            $table->unsignedBigInteger('last_seen_run_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'external_id']);
        });
    }
}

it('filters yandex market feed import by selected category including descendants', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-05 00:00">
  <shop>
    <categories>
      <category id="22">Root 22</category>
      <category id="31" parentId="22">Child 31</category>
      <category id="44">Other 44</category>
    </categories>
    <offers>
      <offer id="A1" available="true">
        <name>Category 22 product</name>
        <price>123</price>
        <currencyId>RUB</currencyId>
        <categoryId>22</categoryId>
      </offer>
      <offer id="A2" available="true">
        <name>Category 31 product</name>
        <price>999</price>
        <currencyId>RUB</currencyId>
        <categoryId>31</categoryId>
      </offer>
      <offer id="A3" available="true">
        <name>Category 44 product</name>
        <price>777</price>
        <currencyId>RUB</currencyId>
        <categoryId>44</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_');
    file_put_contents($path, $xml);

    try {
        $result = app(YandexMarketFeedImportService::class)->run([
            'source' => $path,
            'category_id' => 22,
            'write' => false,
            'limit' => 0,
            'delay_ms' => 0,
            'show_samples' => 3,
        ]);

        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(2);
        expect($result['processed'])->toBe(2);
        expect($result['errors'])->toBe(0);
        expect($result['success'])->toBeTrue();
        expect($result['samples'])->toHaveCount(2);
        expect($result['samples'][0]['external_id'])->toBe('A1');
        expect($result['samples'][1]['external_id'])->toBe('A2');
    } finally {
        @unlink($path);
    }
});
