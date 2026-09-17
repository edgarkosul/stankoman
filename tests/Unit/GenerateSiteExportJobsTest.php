<?php

use App\Jobs\GenerateMarketFeedJob;
use App\Jobs\GenerateSitemapFilesJob;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Feeds\YandexMarketFeedGenerator;
use App\Support\Seo\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('generate sitemap files job creates files and notifies initiator', function (): void {
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('app.robots_allow_indexing', true);

    $user = User::factory()->create();

    Page::factory()->create([
        'slug' => 'home',
        'title' => 'Главная',
        'is_published' => true,
    ]);

    $category = Category::query()->create([
        'name' => 'Станки',
        'slug' => 'stanki',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Токарный станок TEST-500',
        'slug' => 'tokarnyj-stanok-test-500',
        'price_amount' => 125000,
        'is_active' => true,
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $job = new GenerateSitemapFilesJob($user->id);
    $job->handle(app(SitemapGenerator::class));

    expect(Storage::disk('local')->exists('sitemaps/sitemap.xml'))->toBeTrue()
        ->and(Storage::disk('local')->exists('sitemaps/sitemap-products-1.xml'))->toBeTrue();

    $notification = $user->fresh()->notifications()->latest()->first();

    expect($notification)->not->toBeNull()
        ->and((string) data_get($notification?->data, 'format'))->toBe('filament')
        ->and((string) data_get($notification?->data, 'title'))->toContain('Генерация sitemap завершена')
        ->and((string) data_get($notification?->data, 'body'))->toContain('URL товаров');
});

it('generate market feed job creates file and notifies initiator', function (): void {
    Storage::fake('public');

    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('settings.general.shop_name', 'InterTooler Test');

    $user = User::factory()->create();

    $category = Category::query()->create([
        'name' => 'Фрезерные станки',
        'slug' => 'frezernye-stanki',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Фрезерный станок TEST-200',
        'slug' => 'frezernyj-stanok-test-200',
        'brand' => 'InterTooler',
        'price_amount' => 300000,
        'discount_price' => 275000,
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $job = new GenerateMarketFeedJob($user->id);
    $job->handle(app(YandexMarketFeedGenerator::class));

    expect(Storage::disk('public')->exists('feeds/yandex-market.xml'))->toBeTrue();

    $notification = $user->fresh()->notifications()->latest()->first();

    expect($notification)->not->toBeNull()
        ->and((string) data_get($notification?->data, 'format'))->toBe('filament')
        ->and((string) data_get($notification?->data, 'title'))->toContain('Генерация market.xml завершена')
        ->and((string) data_get($notification?->data, 'body'))->toContain('Товарных предложений');
});
