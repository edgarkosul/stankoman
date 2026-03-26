<?php

use App\Support\NameNormalizer;
use App\Support\Products\ProductSearchSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('reports planned yandex vendor.model repairs in dry-run mode without touching data', function (): void {
    rebuildRepairYandexVendorModelSchemas();

    try {
        $context = seedRepairYandexVendorModelFixtures();

        $searchSync = Mockery::mock(ProductSearchSync::class);
        $searchSync->shouldNotReceive('syncIds');
        app()->instance(ProductSearchSync::class, $searchSync);

        $this->artisan('catalog:repair-yandex-vendor-model-names', [
            '--supplier-id' => $context['supplier_id'],
            '--source' => $context['feed_path'],
        ])
            ->expectsOutputToContain('Режим: dry-run')
            ->assertSuccessful();

        expect(DB::table('products')->where('id', $context['legacy_product_id'])->value('name'))
            ->toBe('JIB JIB MBS 350 Ленточнопильный станок')
            ->and(DB::table('products')->where('id', $context['seo_only_product_id'])->value('meta_title'))
            ->toBe('Warrior WARRIOR W0801 230В Ленточнопильный станок');
    } finally {
        @unlink($context['feed_path']);
        dropRepairYandexVendorModelSchemas();
    }
});

it('updates duplicated imported names and meta titles while skipping manual overrides', function (): void {
    rebuildRepairYandexVendorModelSchemas();

    try {
        $context = seedRepairYandexVendorModelFixtures();

        $searchSync = Mockery::mock(ProductSearchSync::class);
        $searchSync->shouldReceive('syncIds')
            ->once()
            ->with(Mockery::on(function (array $ids) use ($context): bool {
                sort($ids);

                return $ids === [
                    $context['legacy_product_id'],
                    $context['seo_only_product_id'],
                ];
            }))
            ->andReturn([
                'synced' => 2,
                'removed' => 0,
            ]);
        app()->instance(ProductSearchSync::class, $searchSync);

        $this->artisan('catalog:repair-yandex-vendor-model-names', [
            '--supplier-id' => $context['supplier_id'],
            '--source' => $context['feed_path'],
            '--write' => true,
        ])
            ->expectsOutputToContain('Режим: write')
            ->assertSuccessful();

        expectProductState(
            productId: $context['legacy_product_id'],
            name: 'JIB MBS 350 Ленточнопильный станок',
            metaTitle: 'JIB MBS 350 Ленточнопильный станок',
            title: 'JIB MBS 350 Ленточнопильный станок',
        );
        expect(DB::table('products')->where('id', $context['legacy_product_id'])->value('name_normalized'))
            ->toBe(NameNormalizer::normalize('JIB MBS 350 Ленточнопильный станок'));

        expectProductState(
            productId: $context['seo_only_product_id'],
            name: 'WARRIOR W0801 230В Ленточнопильный станок',
            metaTitle: 'WARRIOR W0801 230В Ленточнопильный станок',
            title: null,
        );

        expectProductState(
            productId: $context['manual_product_id'],
            name: 'Ручное имя',
            metaTitle: 'Warrior WARRIOR W0802 230В Ленточнопильный станок',
            title: null,
        );
    } finally {
        @unlink($context['feed_path']);
        dropRepairYandexVendorModelSchemas();
    }
});

/**
 * @return array{
 *     supplier_id:int,
 *     feed_path:string,
 *     legacy_product_id:int,
 *     seo_only_product_id:int,
 *     manual_product_id:int
 * }
 */
function seedRepairYandexVendorModelFixtures(): array
{
    $feedPath = tempnam(sys_get_temp_dir(), 'harvey_vendor_model_');
    file_put_contents($feedPath, repairYandexVendorModelFeedXml());

    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => 'Харви',
        'slug' => 'xarvi',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('supplier_import_sources')->insert([
        'supplier_id' => $supplierId,
        'name' => 'Основной feed',
        'driver_key' => 'yandex_market_feed',
        'profile_key' => 'yandex_market_feed_yml',
        'settings' => json_encode([
            'source_mode' => 'url',
            'source_url' => $feedPath,
        ], JSON_THROW_ON_ERROR),
        'is_active' => true,
        'sort' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legacyProductId = DB::table('products')->insertGetId([
        'name' => 'JIB JIB MBS 350 Ленточнопильный станок',
        'name_normalized' => NameNormalizer::normalize('JIB JIB MBS 350 Ленточнопильный станок'),
        'title' => 'JIB JIB MBS 350 Ленточнопильный станок',
        'slug' => 'jib-jib-mbs-350',
        'brand' => 'JIB',
        'price_amount' => 249900,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'description' => '<p></p>',
        'extra_description' => '<p></p>',
        'meta_title' => 'JIB JIB MBS 350 Ленточнопильный станок',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $seoOnlyProductId = DB::table('products')->insertGetId([
        'name' => 'WARRIOR W0801 230В Ленточнопильный станок',
        'name_normalized' => NameNormalizer::normalize('WARRIOR W0801 230В Ленточнопильный станок'),
        'title' => null,
        'slug' => 'warrior-w0801-230v',
        'brand' => 'Warrior',
        'price_amount' => 162000,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'description' => '<p></p>',
        'extra_description' => '<p></p>',
        'meta_title' => 'Warrior WARRIOR W0801 230В Ленточнопильный станок',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manualProductId = DB::table('products')->insertGetId([
        'name' => 'Ручное имя',
        'name_normalized' => NameNormalizer::normalize('Ручное имя'),
        'title' => null,
        'slug' => 'manual-name',
        'brand' => 'Warrior',
        'price_amount' => 165000,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'with_dns' => true,
        'description' => '<p></p>',
        'extra_description' => '<p></p>',
        'meta_title' => 'Warrior WARRIOR W0802 230В Ленточнопильный станок',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('product_supplier_references')->insert([
        [
            'supplier' => 'yandex_market_feed',
            'supplier_id' => $supplierId,
            'external_id' => '7094',
            'product_id' => $legacyProductId,
            'first_seen_run_id' => null,
            'last_seen_run_id' => null,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'supplier' => 'yandex_market_feed',
            'supplier_id' => $supplierId,
            'external_id' => '91547',
            'product_id' => $seoOnlyProductId,
            'first_seen_run_id' => null,
            'last_seen_run_id' => null,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'supplier' => 'yandex_market_feed',
            'supplier_id' => $supplierId,
            'external_id' => '148406',
            'product_id' => $manualProductId,
            'first_seen_run_id' => null,
            'last_seen_run_id' => null,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    return [
        'supplier_id' => $supplierId,
        'feed_path' => $feedPath,
        'legacy_product_id' => $legacyProductId,
        'seo_only_product_id' => $seoOnlyProductId,
        'manual_product_id' => $manualProductId,
    ];
}

function expectProductState(int $productId, string $name, ?string $metaTitle, ?string $title): void
{
    expect(DB::table('products')->where('id', $productId)->value('name'))->toBe($name)
        ->and(DB::table('products')->where('id', $productId)->value('meta_title'))->toBe($metaTitle)
        ->and(DB::table('products')->where('id', $productId)->value('title'))->toBe($title);
}

function rebuildRepairYandexVendorModelSchemas(): void
{
    dropRepairYandexVendorModelSchemas();

    Schema::create('suppliers', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('supplier_import_sources', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('supplier_id');
        $table->string('name');
        $table->string('driver_key');
        $table->string('profile_key');
        $table->json('settings')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('sort')->default(0);
        $table->timestamp('last_used_at')->nullable();
        $table->timestamps();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->string('brand')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->boolean('with_dns')->default(true);
        $table->longText('description')->nullable();
        $table->text('extra_description')->nullable();
        $table->string('meta_title')->nullable();
        $table->timestamps();
    });

    Schema::create('product_supplier_references', function (Blueprint $table): void {
        $table->id();
        $table->string('supplier', 120)->nullable();
        $table->unsignedBigInteger('supplier_id')->nullable();
        $table->string('external_id');
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('first_seen_run_id')->nullable();
        $table->unsignedBigInteger('last_seen_run_id')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->unique(['supplier_id', 'external_id']);
    });
}

function dropRepairYandexVendorModelSchemas(): void
{
    Schema::dropIfExists('product_supplier_references');
    Schema::dropIfExists('products');
    Schema::dropIfExists('supplier_import_sources');
    Schema::dropIfExists('suppliers');
    DB::disconnect();
}

function repairYandexVendorModelFeedXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-26T13:32:02+03:00">
  <shop>
    <offers>
      <offer id="7094" type="vendor.model" available="true">
        <name>JIB MBS 350 Ленточнопильный станок</name>
        <vendor>JIB</vendor>
        <model>JIB MBS 350 Ленточнопильный станок</model>
        <price>249900</price>
        <currencyId>RUR</currencyId>
        <categoryId>17</categoryId>
      </offer>
      <offer id="91547" type="vendor.model" available="true">
        <name>WARRIOR W0801 230В Ленточнопильный станок</name>
        <vendor>Warrior</vendor>
        <model>WARRIOR W0801 230В Ленточнопильный станок</model>
        <price>162000</price>
        <currencyId>RUR</currencyId>
        <categoryId>17</categoryId>
      </offer>
      <offer id="148406" type="vendor.model" available="true">
        <name>WARRIOR W0802 230В Ленточнопильный станок</name>
        <vendor>Warrior</vendor>
        <model>WARRIOR W0802 230В Ленточнопильный станок</model>
        <price>165000</price>
        <currencyId>RUR</currencyId>
        <categoryId>17</categoryId>
      </offer>
    </offers>
  </shop>
</yml_catalog>
XML;
}
