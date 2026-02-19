<?php

use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    Cache::flush();

    Schema::disableForeignKeyConstraints();

    foreach ([
        'product_categories',
        'products',
        'categories',
        'menus',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();

    Schema::create('menus', function (Blueprint $table): void {
        $table->id();
        $table->string('key')->unique();
        $table->string('name');
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

        $table->unique(['parent_id', 'slug'], 'categories_parent_slug_unique');
        $table->unique(['parent_id', 'order'], 'categories_parent_order_unique');
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->boolean('is_active')->default(true)->index();
        $table->timestamps();
    });

    Schema::create('product_categories', function (Blueprint $table): void {
        $table->unsignedBigInteger('product_id');
        $table->unsignedBigInteger('category_id');
        $table->boolean('is_primary')->default(false);
        $table->primary(['product_id', 'category_id']);
    });
});

it('hides inactive root categories on catalog page', function (): void {
    $activeCategory = Category::query()->create([
        'name' => 'Visible Root Category',
        'slug' => 'visible-root-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $inactiveCategory = Category::query()->create([
        'name' => 'Hidden Root Category',
        'slug' => 'hidden-root-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => false,
    ]);

    $this->get(route('catalog.leaf'))
        ->assertSuccessful()
        ->assertSee($activeCategory->name)
        ->assertDontSee($inactiveCategory->name);
});

it('returns 404 for inactive category path', function (): void {
    $inactiveCategory = Category::query()->create([
        'name' => 'Unavailable Category',
        'slug' => 'unavailable-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => false,
    ]);

    $this->get(route('catalog.leaf', ['path' => $inactiveCategory->slug]))
        ->assertNotFound();
});

it('hides inactive branch subcategories', function (): void {
    $parentCategory = Category::query()->create([
        'name' => 'Parent Category',
        'slug' => 'parent-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $activeChild = Category::query()->create([
        'name' => 'Visible Child Category',
        'slug' => 'visible-child-category',
        'parent_id' => $parentCategory->getKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $inactiveChild = Category::query()->create([
        'name' => 'Hidden Child Category',
        'slug' => 'hidden-child-category',
        'parent_id' => $parentCategory->getKey(),
        'order' => 2,
        'is_active' => false,
    ]);

    $this->get(route('catalog.leaf', ['path' => $parentCategory->slug]))
        ->assertSuccessful()
        ->assertSee($activeChild->name)
        ->assertDontSee($inactiveChild->name);
});
