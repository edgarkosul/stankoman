<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Support\Seo\SitemapGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');

    $this->sitemaps = app(SitemapGenerator::class);
});

it('generates sitemap and product sitemap files outside the public directory', function (): void {
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

    expect(Storage::disk('local')->exists('sitemaps/sitemap.xml'))->toBeTrue()
        ->and(Storage::disk('local')->exists('sitemaps/sitemap-static.xml'))->toBeTrue()
        ->and(Storage::disk('local')->exists('sitemaps/sitemap-categories.xml'))->toBeTrue()
        ->and(Storage::disk('local')->exists('sitemaps/sitemap-products-1.xml'))->toBeTrue()
        ->and(File::exists(public_path('sitemap.xml')))->toBeFalse()
        ->and(File::exists(public_path('robots.txt')))->toBeFalse();

    $index = File::get($this->sitemaps->path('sitemap.xml'));
    $static = File::get($this->sitemaps->path('sitemap-static.xml'));
    $categories = File::get($this->sitemaps->path('sitemap-categories.xml'));
    $products = File::get($this->sitemaps->path('sitemap-products-1.xml'));

    expect($index)->toContain('https://settings.example.com/sitemap-static.xml')
        ->toContain('https://settings.example.com/sitemap-categories.xml')
        ->toContain('https://settings.example.com/sitemap-products-1.xml');

    expect($static)->toContain('https://settings.example.com/')
        ->toContain('https://settings.example.com/page/about')
        ->not->toContain('https://settings.example.com/page/home');

    expect($categories)->toContain('https://settings.example.com/catalog/stanki')
        ->toContain('https://settings.example.com/catalog/stanki/tokarnye');

    expect($products)->toContain('https://settings.example.com/product/tokarnyj-stanok-test-500');
});

it('removes product sitemap files left from a bigger catalog', function (): void {
    File::ensureDirectoryExists($this->sitemaps->directory());
    File::put($this->sitemaps->path('sitemap-products-7.xml'), '<urlset/>');

    $this->artisan('seo:generate-sitemap')->assertSuccessful();

    expect(File::exists($this->sitemaps->path('sitemap-products-7.xml')))->toBeFalse();
});
