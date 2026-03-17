<?php

use App\Models\Category;
use App\Models\Product;
use App\View\Components\Product\Popular;

function createPopularSliderCategory(array $attributes = []): Category
{
    static $counter = 1;
    $index = $counter++;

    return Category::query()->create(array_merge([
        'name' => "Popular Slider Category {$index}",
        'slug' => "popular-slider-category-{$index}",
        'parent_id' => -1,
        'order' => $index,
        'is_active' => true,
    ], $attributes));
}

function createPopularSliderProduct(array $attributes = []): Product
{
    static $counter = 1;
    $index = $counter++;

    return Product::query()->create(array_merge([
        'name' => "Popular Slider Product {$index}",
        'slug' => "popular-slider-product-{$index}",
        'price_amount' => 100_000,
        'discount_price' => null,
        'is_active' => true,
        'in_stock' => true,
        'popularity' => 0,
    ], $attributes));
}

test('popular products component on category page uses current category and descendant categories', function (): void {
    $parentCategory = createPopularSliderCategory([
        'name' => 'Popular Branch Category',
        'slug' => 'popular-branch-category',
        'order' => 501,
    ]);

    $leafCategory = createPopularSliderCategory([
        'name' => 'Popular Leaf Category',
        'slug' => 'popular-leaf-category',
        'parent_id' => $parentCategory->id,
        'order' => 1,
    ]);

    $otherCategory = createPopularSliderCategory([
        'name' => 'Popular Other Category',
        'slug' => 'popular-other-category',
        'order' => 502,
    ]);

    $expectedIds = collect([
        900 => createPopularSliderProduct([
            'name' => 'Category Popular Product 1',
            'slug' => 'category-popular-product-1',
            'popularity' => 900,
        ]),
        800 => createPopularSliderProduct([
            'name' => 'Category Popular Product 2',
            'slug' => 'category-popular-product-2',
            'popularity' => 800,
        ]),
        700 => createPopularSliderProduct([
            'name' => 'Category Popular Product 3',
            'slug' => 'category-popular-product-3',
            'popularity' => 700,
        ]),
        600 => createPopularSliderProduct([
            'name' => 'Category Popular Product 4',
            'slug' => 'category-popular-product-4',
            'popularity' => 600,
        ]),
        500 => createPopularSliderProduct([
            'name' => 'Category Popular Product 5',
            'slug' => 'category-popular-product-5',
            'popularity' => 500,
        ]),
    ])->map(function (Product $product) use ($leafCategory): int {
        $product->categories()->attach($leafCategory->id, ['is_primary' => true]);

        return $product->id;
    })->values();

    $otherProduct = createPopularSliderProduct([
        'name' => 'Out Of Scope Popular Product',
        'slug' => 'out-of-scope-popular-product',
        'popularity' => 1_000,
    ]);
    $otherProduct->categories()->attach($otherCategory->id, ['is_primary' => true]);

    $component = new Popular(category: $parentCategory);

    expect($component->shouldRender())->toBeTrue()
        ->and($component->products->pluck('id')->all())
        ->toBe($expectedIds->all())
        ->and($component->products->pluck('id'))
        ->not->toContain($otherProduct->id);
});

test('category page renders popular products slider only when at least five scoped products exist', function (): void {
    $parentCategory = createPopularSliderCategory([
        'name' => 'Rendered Popular Branch Category',
        'slug' => 'rendered-popular-branch-category',
        'order' => 601,
    ]);

    $leafCategory = createPopularSliderCategory([
        'name' => 'Rendered Popular Leaf Category',
        'slug' => 'rendered-popular-leaf-category',
        'parent_id' => $parentCategory->id,
        'order' => 1,
    ]);

    foreach (range(1, 4) as $index) {
        $product = createPopularSliderProduct([
            'name' => "Rendered Popular Product {$index}",
            'slug' => "rendered-popular-product-{$index}",
            'popularity' => $index,
        ]);
        $product->categories()->attach($leafCategory->id, ['is_primary' => true]);
    }

    $this->get(route('catalog.leaf', ['path' => $parentCategory->slug]))
        ->assertSuccessful()
        ->assertDontSee('Популярные товары:');

    $fifthProduct = createPopularSliderProduct([
        'name' => 'Rendered Popular Product 5',
        'slug' => 'rendered-popular-product-5',
        'popularity' => 5,
    ]);
    $fifthProduct->categories()->attach($leafCategory->id, ['is_primary' => true]);

    $this->get(route('catalog.leaf', ['path' => $parentCategory->slug]))
        ->assertSuccessful()
        ->assertSee('Популярные товары:')
        ->assertSee('product-slider');
});
