<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

it('repairs unambiguous non leaf links and creates a leaf for the electric quad', function (): void {
    $parent = Category::query()->create([
        'name' => 'Родитель для однозначного исправления',
        'slug' => 'parent-for-unambiguous-category-repair',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'name' => 'Товар для однозначного исправления',
        'slug' => 'product-for-unambiguous-category-repair',
        'price_amount' => 1000,
    ]);
    $parent->products()->attach($product->getKey(), ['is_primary' => true]);

    $leafId = DB::table('categories')->insertGetId([
        'name' => 'Однозначный лист',
        'slug' => 'leaf-for-unambiguous-category-repair',
        'parent_id' => $parent->getKey(),
        'order' => 1,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('product_categories')->insert([
        'product_id' => $product->getKey(),
        'category_id' => $leafId,
        'is_primary' => false,
    ]);

    $electricTransport = Category::query()->create([
        'name' => 'Электротранспорт',
        'slug' => 'electric-transport-for-category-repair',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);
    $quad = Product::query()->create([
        'name' => 'Детский электроквадроцикл sneg leto R RED',
        'slug' => 'detskii-elektrokvadrocikl-sneg-leto-r-red',
        'price_amount' => 2000,
    ]);
    $electricTransport->products()->attach($quad->getKey(), ['is_primary' => true]);
    DB::table('categories')->insert([
        'name' => 'Электроскутеры',
        'slug' => 'electric-scooters-for-category-repair',
        'parent_id' => $electricTransport->getKey(),
        'order' => 1,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_08_24_074801_repair_products_linked_to_non_leaf_categories.php');
    $migration->up();

    expect(DB::table('product_categories')
        ->where('product_id', $product->getKey())
        ->where('category_id', $parent->getKey())
        ->exists())->toBeFalse()
        ->and(DB::table('product_categories')
            ->where('product_id', $product->getKey())
            ->where('category_id', $leafId)
            ->value('is_primary'))->toBe(1);

    $quadCategoryId = DB::table('categories')
        ->where('parent_id', $electricTransport->getKey())
        ->where('slug', 'elektrokvadrocikly')
        ->value('id');

    expect($quadCategoryId)->not->toBeNull()
        ->and(DB::table('product_categories')
            ->where('product_id', $quad->getKey())
            ->where('category_id', $electricTransport->getKey())
            ->exists())->toBeFalse()
        ->and(DB::table('product_categories')
            ->where('product_id', $quad->getKey())
            ->where('category_id', $quadCategoryId)
            ->value('is_primary'))->toBe(1);
});
