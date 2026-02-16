<?php

use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    Schema::disableForeignKeyConstraints();

    foreach ([
        'product_categories',
        'products',
        'categories',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();

    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->integer('parent_id')->default(-1);
        $table->string('name');
        $table->string('slug')->unique();
        $table->string('img')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('order')->default(0);
        $table->json('meta_json')->nullable();
        $table->timestamps();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('slug')->unique();
        $table->unsignedInteger('price_amount')->default(0);
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->unsignedInteger('popularity')->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->boolean('with_dns')->default(true);
        $table->timestamps();
    });

    Schema::create('product_categories', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('category_id');
        $table->boolean('is_primary')->default(false);
        $table->primary(['product_id', 'category_id']);
    });
});

test('available as parent scope keeps non leaf and leaf without products', function () {
    $nonLeafWithProducts = Category::query()->create([
        'name' => 'Non-leaf with products',
        'slug' => 'non-leaf-with-products',
        'parent_id' => -1,
        'order' => 1,
        'is_active' => true,
    ]);

    Category::query()->create([
        'name' => 'Child category',
        'slug' => 'child-category',
        'parent_id' => $nonLeafWithProducts->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $leafWithoutProducts = Category::query()->create([
        'name' => 'Leaf without products',
        'slug' => 'leaf-without-products',
        'parent_id' => -1,
        'order' => 2,
        'is_active' => true,
    ]);

    $leafWithProducts = Category::query()->create([
        'name' => 'Leaf with products',
        'slug' => 'leaf-with-products',
        'parent_id' => -1,
        'order' => 3,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Demo product',
        'slug' => 'demo-product-parent-candidates',
    ]);

    $leafWithProducts->products()->attach($product->id, [
        'is_primary' => true,
    ]);

    $candidateIds = Category::query()
        ->availableAsParent()
        ->pluck('id')
        ->all();

    expect($candidateIds)
        ->toContain($nonLeafWithProducts->id, $leafWithoutProducts->id)
        ->not->toContain($leafWithProducts->id);

    $categoryOptionsMethod = new ReflectionMethod(CategoryForm::class, 'categoryOptions');
    $categoryOptionsMethod->setAccessible(true);
    $options = $categoryOptionsMethod->invoke(null);

    expect($options)->toHaveKey('-1')
        ->and(array_key_exists($nonLeafWithProducts->id, $options))->toBeTrue()
        ->and(array_key_exists($leafWithoutProducts->id, $options))->toBeTrue()
        ->and(array_key_exists($leafWithProducts->id, $options))->toBeFalse();
});
