<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSupplierReference;
use App\Models\Supplier;
use App\Support\CatalogImport\Contracts\SourceResolverInterface;
use App\Support\CatalogImport\DTO\ResolvedSource;
use App\Support\CatalogImport\Yml\YandexMarketFeedDeactivationService;
use App\Support\CatalogImport\Yml\YmlStreamParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    prepareYandexMarketFeedDeactivationServiceTables();
});

it('collects dry-run candidates only within selected supplier and site category scope', function () {
    [$supplier, $rootCategory, $childCategory, $otherCategory] = seedDeactivationSuppliersAndCategories();

    $keepProduct = createDeactivationProduct(
        name: 'Keep product',
        categoryIds: [$childCategory->id],
        externalId: 'KEEP-1',
        supplierId: $supplier->id,
    );
    $candidateProduct = createDeactivationProduct(
        name: 'Deactivate me',
        categoryIds: [$childCategory->id],
        externalId: 'MISS-1',
        supplierId: $supplier->id,
    );
    createDeactivationProduct(
        name: 'Outside category',
        categoryIds: [$otherCategory->id],
        externalId: 'OUTSIDE-1',
        supplierId: $supplier->id,
    );

    $otherSupplier = Supplier::query()->create([
        'name' => 'Other Supplier',
        'is_active' => true,
    ]);

    createDeactivationProduct(
        name: 'Other supplier product',
        categoryIds: [$childCategory->id],
        externalId: 'OTHER-SUPPLIER-1',
        supplierId: $otherSupplier->id,
    );

    $inactiveProduct = createDeactivationProduct(
        name: 'Inactive product',
        categoryIds: [$childCategory->id],
        externalId: 'INACTIVE-1',
        supplierId: $supplier->id,
        isActive: false,
    );

    $path = createDeactivationFeedPath(['KEEP-1']);
    $service = makeYandexMarketFeedDeactivationService($path);

    try {
        $result = $service->run([
            'source' => 'https://example.test/deactivate.xml',
            'supplier_id' => $supplier->id,
            'site_category_id' => $rootCategory->id,
            'show_samples' => 10,
            'write' => false,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['fatal_error'])->toBeNull();
        expect($result['no_urls'])->toBeFalse();
        expect($result['found_urls'])->toBe(1);
        expect($result['processed'])->toBe(2);
        expect($result['candidates'])->toBe(1);
        expect($result['deactivated'])->toBe(0);
        expect($result['samples'])->toHaveCount(1);
        expect($result['samples'][0]['external_id'])->toBe('MISS-1');
        expect($result['samples'][0]['product_id'])->toBe($candidateProduct->id);

        expect($keepProduct->fresh()->is_active)->toBeTrue();
        expect($candidateProduct->fresh()->is_active)->toBeTrue();
        expect($inactiveProduct->fresh()->is_active)->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('deactivates only missing supplier products inside selected site category scope', function () {
    [$supplier, $rootCategory, $childCategory, $otherCategory] = seedDeactivationSuppliersAndCategories();

    $keepProduct = createDeactivationProduct(
        name: 'Keep product',
        categoryIds: [$childCategory->id],
        externalId: 'KEEP-1',
        supplierId: $supplier->id,
        qty: 7,
    );
    $candidateProduct = createDeactivationProduct(
        name: 'Deactivate me',
        categoryIds: [$childCategory->id],
        externalId: 'MISS-1',
        supplierId: $supplier->id,
        qty: 9,
    );
    $outsideCategoryProduct = createDeactivationProduct(
        name: 'Outside category',
        categoryIds: [$otherCategory->id],
        externalId: 'OUTSIDE-1',
        supplierId: $supplier->id,
        qty: 5,
    );

    $otherSupplier = Supplier::query()->create([
        'name' => 'Other Supplier',
        'is_active' => true,
    ]);

    $otherSupplierProduct = createDeactivationProduct(
        name: 'Other supplier product',
        categoryIds: [$childCategory->id],
        externalId: 'OTHER-SUPPLIER-1',
        supplierId: $otherSupplier->id,
        qty: 3,
    );

    $path = createDeactivationFeedPath(['KEEP-1']);
    $service = makeYandexMarketFeedDeactivationService($path);

    try {
        $result = $service->run([
            'source' => 'https://example.test/deactivate.xml',
            'supplier_id' => $supplier->id,
            'site_category_id' => $rootCategory->id,
            'show_samples' => 10,
            'write' => true,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['candidates'])->toBe(1);
        expect($result['deactivated'])->toBe(1);

        expect($candidateProduct->fresh()->is_active)->toBeFalse();
        expect($candidateProduct->fresh()->in_stock)->toBeFalse();
        expect($candidateProduct->fresh()->qty)->toBe(0);

        expect($keepProduct->fresh()->is_active)->toBeTrue();
        expect($keepProduct->fresh()->qty)->toBe(7);
        expect($outsideCategoryProduct->fresh()->is_active)->toBeTrue();
        expect($outsideCategoryProduct->fresh()->qty)->toBe(5);
        expect($otherSupplierProduct->fresh()->is_active)->toBeTrue();
        expect($otherSupplierProduct->fresh()->qty)->toBe(3);
    } finally {
        @unlink($path);
    }
});

function prepareYandexMarketFeedDeactivationServiceTables(): void
{
    Schema::dropIfExists('product_supplier_references');
    Schema::dropIfExists('product_categories');
    Schema::dropIfExists('products');
    Schema::dropIfExists('categories');
    Schema::dropIfExists('suppliers');

    Schema::create('suppliers', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug');
        $table->string('img')->nullable();
        $table->boolean('is_active')->default(true);
        $table->integer('parent_id')->default(-1)->index();
        $table->integer('order')->default(0)->index();
        $table->json('meta_json')->nullable();
        $table->timestamps();

        $table->unique(['parent_id', 'slug']);
        $table->unique(['parent_id', 'order']);
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized');
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->string('sku')->nullable()->index();
        $table->string('brand')->nullable()->index();
        $table->string('country')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true)->index();
        $table->unsignedInteger('qty')->nullable();
        $table->unsignedInteger('popularity')->default(0)->index();
        $table->boolean('is_active')->default(true)->index();
        $table->boolean('is_in_yml_feed')->default(true)->index();
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

    Schema::create('product_categories', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('category_id');
        $table->boolean('is_primary')->default(false);

        $table->primary(['product_id', 'category_id']);
        $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
    });

    Schema::create('product_supplier_references', function (Blueprint $table): void {
        $table->id();
        $table->string('supplier', 120);
        $table->unsignedBigInteger('supplier_id')->nullable();
        $table->string('external_id');
        $table->unsignedInteger('source_category_id')->nullable();
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('first_seen_run_id')->nullable();
        $table->unsignedBigInteger('last_seen_run_id')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();

        $table->unique(['supplier_id', 'external_id']);
        $table->index(['supplier_id', 'product_id']);
        $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
    });
}

/**
 * @return array{0:Supplier,1:Category,2:Category,3:Category}
 */
function seedDeactivationSuppliersAndCategories(): array
{
    $supplier = Supplier::query()->create([
        'name' => 'Feed Supplier',
        'is_active' => true,
    ]);

    $rootCategory = Category::query()->create([
        'name' => 'Root',
        'slug' => 'root',
        'is_active' => true,
        'parent_id' => -1,
        'order' => 1,
    ]);

    $childCategory = Category::query()->create([
        'name' => 'Child',
        'slug' => 'child',
        'is_active' => true,
        'parent_id' => $rootCategory->id,
        'order' => 1,
    ]);

    $otherCategory = Category::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'is_active' => true,
        'parent_id' => -1,
        'order' => 2,
    ]);

    return [$supplier, $rootCategory, $childCategory, $otherCategory];
}

function createDeactivationProduct(
    string $name,
    array $categoryIds,
    string $externalId,
    int $supplierId,
    bool $isActive = true,
    bool $inStock = true,
    int $qty = 1,
): Product {
    $product = Product::query()->create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
        'price_amount' => 1000,
        'currency' => 'RUB',
        'in_stock' => $inStock,
        'qty' => $qty,
        'is_active' => $isActive,
        'is_in_yml_feed' => true,
        'with_dns' => true,
    ]);

    $product->categories()->sync($categoryIds);

    ProductSupplierReference::query()->create([
        'supplier' => 'yandex_market_feed',
        'supplier_id' => $supplierId,
        'external_id' => $externalId,
        'product_id' => $product->id,
        'last_seen_at' => now(),
    ]);

    return $product;
}

function createDeactivationFeedPath(array $externalIds): string
{
    $offersXml = collect($externalIds)
        ->map(fn (string $externalId): string => <<<XML
      <offer id="{$externalId}" available="true">
        <name>{$externalId}</name>
        <price>100</price>
        <currencyId>RUB</currencyId>
        <categoryId>1</categoryId>
      </offer>
XML)
        ->implode("\n");

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2026-03-13 00:00">
  <shop>
    <offers>
{$offersXml}
    </offers>
  </shop>
</yml_catalog>
XML;

    $path = tempnam(sys_get_temp_dir(), 'yandex_feed_deactivate_');
    file_put_contents($path, $xml);

    return $path;
}

function makeYandexMarketFeedDeactivationService(string $path): YandexMarketFeedDeactivationService
{
    return new YandexMarketFeedDeactivationService(
        new YmlStreamParser,
        new class($path) implements SourceResolverInterface
        {
            public function __construct(private readonly string $path) {}

            public function resolve(string $source, array $options = []): ResolvedSource
            {
                return new ResolvedSource($source, $this->path);
            }
        },
    );
}
