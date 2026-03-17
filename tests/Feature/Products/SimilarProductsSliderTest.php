<?php

use App\Models\Category;
use App\Models\Product;
use App\View\Components\Product\Popular;
use App\View\Components\Product\Similar;

function createSimilarSliderCategory(array $attributes = []): Category
{
    static $counter = 1;
    $index = $counter++;

    return Category::query()->create(array_merge([
        'name' => "Similar Category {$index}",
        'slug' => "similar-category-{$index}",
        'parent_id' => -1,
        'order' => $index,
        'is_active' => true,
    ], $attributes));
}

function createSimilarSliderProduct(array $attributes = []): Product
{
    static $counter = 1;
    $index = $counter++;

    return Product::query()->create(array_merge([
        'name' => "Similar Product {$index}",
        'slug' => "similar-product-{$index}",
        'price_amount' => 100_000,
        'discount_price' => null,
        'is_active' => true,
        'in_stock' => true,
    ], $attributes));
}

test('similar products slider picks active products from the same category and prefers close price range', function (): void {
    $mainCategory = createSimilarSliderCategory([
        'name' => 'Main Similar Category',
        'slug' => 'main-similar-category',
        'order' => 101,
    ]);

    $otherCategory = createSimilarSliderCategory([
        'name' => 'Other Similar Category',
        'slug' => 'other-similar-category',
        'order' => 102,
    ]);

    $currentProduct = createSimilarSliderProduct([
        'name' => 'Current Similar Product',
        'slug' => 'current-similar-product',
        'price_amount' => 100_000,
    ]);
    $currentProduct->categories()->attach($mainCategory->id, ['is_primary' => true]);

    $nearPrices = [99_000, 101_000, 98_000, 102_000, 97_000, 103_000, 96_000, 104_000, 95_000, 105_000];
    $nearProducts = collect($nearPrices)->map(function (int $price, int $index) use ($mainCategory): Product {
        $product = createSimilarSliderProduct([
            'name' => "Near Similar Product {$index}",
            'slug' => "near-similar-product-{$index}",
            'price_amount' => $price,
        ]);

        $product->categories()->attach($mainCategory->id, ['is_primary' => true]);

        return $product;
    });

    $farProduct = createSimilarSliderProduct([
        'name' => 'Far Similar Product',
        'slug' => 'far-similar-product',
        'price_amount' => 700_000,
    ]);
    $farProduct->categories()->attach($mainCategory->id, ['is_primary' => true]);

    $inactiveProduct = createSimilarSliderProduct([
        'name' => 'Inactive Similar Product',
        'slug' => 'inactive-similar-product',
        'price_amount' => 100_000,
        'is_active' => false,
    ]);
    $inactiveProduct->categories()->attach($mainCategory->id, ['is_primary' => true]);

    $otherCategoryProduct = createSimilarSliderProduct([
        'name' => 'Other Category Similar Product',
        'slug' => 'other-category-similar-product',
        'price_amount' => 100_000,
    ]);
    $otherCategoryProduct->categories()->attach($otherCategory->id, ['is_primary' => true]);

    $component = new Similar($currentProduct);
    $ids = $component->products->pluck('id');

    expect($component->products)
        ->toHaveCount(10);

    expect($ids->all())
        ->toContain(...$nearProducts->pluck('id')->all())
        ->not->toContain(
            $currentProduct->id,
            $farProduct->id,
            $inactiveProduct->id,
            $otherCategoryProduct->id,
        );
});

test('product page renders similar products slider section', function (): void {
    $category = createSimilarSliderCategory([
        'name' => 'Visible Similar Category',
        'slug' => 'visible-similar-category',
        'order' => 201,
    ]);

    $product = createSimilarSliderProduct([
        'name' => 'Visible Similar Current Product',
        'slug' => 'visible-similar-current-product',
        'price_amount' => 250_000,
    ]);
    $product->categories()->attach($category->id, ['is_primary' => true]);

    $similar = createSimilarSliderProduct([
        'name' => 'Visible Similar Candidate',
        'slug' => 'visible-similar-candidate',
        'price_amount' => 245_000,
    ]);
    $similar->categories()->attach($category->id, ['is_primary' => true]);

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('Аналогичные товары:')
        ->assertSee('product-slider');
});

test('popular products component on product page uses primary category scope and excludes current product', function (): void {
    $parentCategory = createSimilarSliderCategory([
        'name' => 'Popular Parent Category',
        'slug' => 'popular-parent-category',
        'order' => 301,
    ]);

    $childCategory = createSimilarSliderCategory([
        'name' => 'Popular Child Category',
        'slug' => 'popular-child-category',
        'parent_id' => $parentCategory->id,
        'order' => 1,
    ]);

    $otherCategory = createSimilarSliderCategory([
        'name' => 'Other Popular Category',
        'slug' => 'other-popular-category',
        'order' => 302,
    ]);

    $product = createSimilarSliderProduct([
        'name' => 'Current Popular Product',
        'slug' => 'current-popular-product',
        'popularity' => 50,
    ]);
    $product->categories()->attach($childCategory->id, ['is_primary' => true]);

    $expectedIds = collect([
        900 => createSimilarSliderProduct([
            'name' => 'Popular Descendant Product 1',
            'slug' => 'popular-descendant-product-1',
            'popularity' => 900,
        ]),
        800 => createSimilarSliderProduct([
            'name' => 'Popular Descendant Product 2',
            'slug' => 'popular-descendant-product-2',
            'popularity' => 800,
        ]),
        700 => createSimilarSliderProduct([
            'name' => 'Popular Descendant Product 3',
            'slug' => 'popular-descendant-product-3',
            'popularity' => 700,
        ]),
        600 => createSimilarSliderProduct([
            'name' => 'Popular Descendant Product 4',
            'slug' => 'popular-descendant-product-4',
            'popularity' => 600,
        ]),
        500 => createSimilarSliderProduct([
            'name' => 'Popular Descendant Product 5',
            'slug' => 'popular-descendant-product-5',
            'popularity' => 500,
        ]),
    ])->map(function (Product $candidate) use ($childCategory): int {
        $candidate->categories()->attach($childCategory->id, ['is_primary' => true]);

        return $candidate->id;
    })->values();

    $otherProduct = createSimilarSliderProduct([
        'name' => 'Other Popular Product',
        'slug' => 'other-popular-product',
        'popularity' => 1_000,
    ]);
    $otherProduct->categories()->attach($otherCategory->id, ['is_primary' => true]);

    $inactiveProduct = createSimilarSliderProduct([
        'name' => 'Inactive Descendant Product',
        'slug' => 'inactive-descendant-product',
        'popularity' => 1_100,
        'is_active' => false,
    ]);
    $inactiveProduct->categories()->attach($childCategory->id, ['is_primary' => true]);

    $component = new Popular(product: $product->load('categories'));

    expect($component->shouldRender())->toBeTrue()
        ->and($component->products->pluck('id')->all())
        ->toBe($expectedIds->all())
        ->and($component->products->pluck('id'))
        ->not->toContain($product->id, $otherProduct->id, $inactiveProduct->id);
});

test('product page renders popular products slider when category scope has at least five items', function (): void {
    $parentCategory = createSimilarSliderCategory([
        'name' => 'Visible Popular Parent Category',
        'slug' => 'visible-popular-parent-category',
        'order' => 401,
    ]);

    $childCategory = createSimilarSliderCategory([
        'name' => 'Visible Popular Child Category',
        'slug' => 'visible-popular-child-category',
        'parent_id' => $parentCategory->id,
        'order' => 1,
    ]);

    $product = createSimilarSliderProduct([
        'name' => 'Visible Popular Current Product',
        'slug' => 'visible-popular-current-product',
    ]);
    $product->categories()->attach($childCategory->id, ['is_primary' => true]);

    foreach (range(1, 5) as $index) {
        $candidate = createSimilarSliderProduct([
            'name' => "Visible Popular Candidate {$index}",
            'slug' => "visible-popular-candidate-{$index}",
            'popularity' => 100 - $index,
        ]);
        $candidate->categories()->attach($childCategory->id, ['is_primary' => true]);
    }

    $this->get(route('product.show', ['product' => $product]))
        ->assertSuccessful()
        ->assertSee('Популярные товары:')
        ->assertSee('product-slider');
});
