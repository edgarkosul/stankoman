<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->originalPublicPath = public_path();
    $this->temporaryPublicPath = storage_path('framework/testing/public-'.Str::uuid());

    File::deleteDirectory($this->temporaryPublicPath);
    File::ensureDirectoryExists($this->temporaryPublicPath);

    app()->usePublicPath($this->temporaryPublicPath);
});

afterEach(function (): void {
    app()->usePublicPath($this->originalPublicPath);
    File::deleteDirectory($this->temporaryPublicPath);
});

it('generates sitemap, robots, and product sitemap files', function (): void {
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('app.robots_allow_indexing', true);

    Page::factory()->create([
        'slug' => 'home',
        'title' => 'Главная',
        'is_published' => true,
        'content' => '<p>Главная страница</p>',
    ]);

    Page::factory()->create([
        'slug' => 'about',
        'title' => 'О компании',
        'is_published' => true,
        'content' => '<p>О компании</p>',
    ]);

    $parentCategory = Category::query()->create([
        'name' => 'Станки',
        'slug' => 'stanki',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $childCategory = Category::query()->create([
        'name' => 'Токарные',
        'slug' => 'tokarnye',
        'parent_id' => $parentCategory->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Токарный станок TEST-500',
        'slug' => 'tokarnyj-stanok-test-500',
        'price_amount' => 125000,
        'is_active' => true,
    ]);

    $product->categories()->attach($childCategory->id, ['is_primary' => true]);

    $this->artisan('seo:generate-sitemap')
        ->expectsOutputToContain('Sitemap index:')
        ->assertSuccessful();

    expect(File::exists(public_path('sitemap.xml')))->toBeTrue()
        ->and(File::exists(public_path('sitemap-static.xml')))->toBeTrue()
        ->and(File::exists(public_path('sitemap-categories.xml')))->toBeTrue()
        ->and(File::exists(public_path('sitemap-products-1.xml')))->toBeTrue()
        ->and(File::exists(public_path('robots.txt')))->toBeTrue();

    $index = File::get(public_path('sitemap.xml'));
    $static = File::get(public_path('sitemap-static.xml'));
    $categories = File::get(public_path('sitemap-categories.xml'));
    $products = File::get(public_path('sitemap-products-1.xml'));
    $robots = File::get(public_path('robots.txt'));

    expect($index)->toContain('https://settings.example.com/sitemap-static.xml')
        ->toContain('https://settings.example.com/sitemap-categories.xml')
        ->toContain('https://settings.example.com/sitemap-products-1.xml');

    expect($static)->toContain('https://settings.example.com/')
        ->toContain('https://settings.example.com/page/about')
        ->not->toContain('https://settings.example.com/page/home');

    expect($categories)->toContain('https://settings.example.com/catalog/stanki')
        ->toContain('https://settings.example.com/catalog/stanki/tokarnye');

    expect($products)->toContain('https://settings.example.com/product/tokarnyj-stanok-test-500');

    expect($robots)->toContain('User-agent: *')
        ->toContain('Disallow: /admin/')
        ->toContain('Sitemap: https://settings.example.com/sitemap.xml');
});

it('writes a blocking robots file when indexing is disabled', function (): void {
    config()->set('app.robots_allow_indexing', false);

    $this->artisan('seo:generate-sitemap')->assertSuccessful();

    expect(File::get(public_path('robots.txt')))
        ->toBe("User-agent: *\nDisallow: /\n");
});
