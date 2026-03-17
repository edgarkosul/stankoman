<?php

use App\Models\Page;
use App\Models\Product;

it('renders organization and website schema with social meta tags on the home page', function (): void {
    Page::factory()->create([
        'slug' => 'home',
        'title' => 'Главная',
        'content' => '<p>Каталог станков и промышленного оборудования для производства.</p>',
        'is_published' => true,
    ]);

    $response = $this->get(route('home'));

    $response->assertSuccessful()
        ->assertSee('<meta property="og:type" content="website" />', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image" />', false)
        ->assertSee('"@type": "Organization"', false)
        ->assertSee('"@type": "WebSite"', false)
        ->assertSee('"SearchAction"', false);
});

it('renders custom meta description for published static pages', function (): void {
    $page = Page::factory()->create([
        'title' => 'О компании',
        'slug' => 'about',
        'content' => '<p>О компании</p>',
        'meta_title' => 'О компании и сервисе',
        'meta_description' => 'Подробная информация о компании и условиях работы.',
        'is_published' => true,
    ]);

    $response = $this->get(route('page.show', ['page' => $page->slug]));

    $response->assertSuccessful()
        ->assertSee('<meta name="description" content="Подробная информация о компании и условиях работы." />', false)
        ->assertSee('<meta property="og:title" content="О компании и сервисе | '.config('app.name').'" />', false)
        ->assertSee('<meta name="twitter:title" content="О компании и сервисе | '.config('app.name').'" />', false);
});

it('renders product schema and product social meta tags on product pages', function (): void {
    $product = Product::query()->create([
        'name' => 'Токарный станок TEST-500',
        'slug' => 'tokarnyj-stanok-test-500',
        'sku' => 'TEST-500',
        'brand' => 'Stankoman',
        'price_amount' => 125000,
        'discount_price' => 119000,
        'is_active' => true,
        'in_stock' => true,
        'meta_title' => 'Токарный станок TEST-500',
        'meta_description' => 'Надежный токарный станок для производственных задач.',
    ]);

    $response = $this->get(route('product.show', ['product' => $product]));

    $response->assertSuccessful()
        ->assertSee('<meta property="og:type" content="product" />', false)
        ->assertSee('<meta property="og:title" content="Токарный станок TEST-500 | '.config('app.name').'" />', false)
        ->assertSee('<meta name="twitter:title" content="Токарный станок TEST-500 | '.config('app.name').'" />', false)
        ->assertSee('"@type": "Product"', false)
        ->assertSee('"priceCurrency": "RUB"', false)
        ->assertSee('"availability": "https://schema.org/InStock"', false)
        ->assertSee('"sku": "TEST-500"', false);
});
