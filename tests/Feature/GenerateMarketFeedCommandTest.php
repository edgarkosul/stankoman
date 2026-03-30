<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

it('generates the market feed file for eligible products', function (): void {
    Storage::fake('public');

    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('company.public_email', 'public@example.com');
    config()->set('settings.general.shop_name', 'InterTooler Test');

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
        'meta_description' => 'Надежный фрезерный станок для производственных задач.',
        'image' => 'products/test-200.jpg',
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    Product::query()->create([
        'name' => 'Скрытый товар',
        'slug' => 'hidden-product',
        'price_amount' => 150000,
        'is_active' => true,
        'is_in_yml_feed' => false,
    ]);

    $this->artisan('feeds:generate-market')
        ->expectsOutputToContain('Offers exported:')
        ->assertSuccessful();

    expect(Storage::disk('public')->exists('feeds/yandex-market.xml'))->toBeTrue();

    $xml = Storage::disk('public')->get('feeds/yandex-market.xml');

    expect($xml)->toContain('<yml_catalog')
        ->toContain('<name>InterTooler Test</name>')
        ->toContain('<company>ООО Тестовая компания</company>')
        ->toContain('<email>public@example.com</email>')
        ->toContain('<currency id="RUR" rate="1"')
        ->toContain('<offer id="'.$product->id.'" available="true">')
        ->toContain('<url>https://settings.example.com/product/frezernyj-stanok-test-200</url>')
        ->toContain('<price>275000</price>')
        ->toContain('<oldprice>300000</oldprice>')
        ->toContain('<categoryId>'.$category->id.'</categoryId>')
        ->toContain('<vendor>InterTooler</vendor>')
        ->toContain('<picture>https://settings.example.com/storage/products/test-200.jpg</picture>')
        ->toContain('<description>Надежный фрезерный станок для производственных задач.</description>')
        ->not->toContain('hidden-product');
});

it('uses cleaned html description when meta description is absent', function (): void {
    Storage::fake('public');

    config()->set('company.site_url', 'https://settings.example.com');

    $category = Category::query()->create([
        'name' => 'Сверлильные станки',
        'slug' => 'sverlilnye-stanki',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Сверлильный станок TEST-100',
        'slug' => 'sverlilnyj-stanok-test-100',
        'price_amount' => 99000,
        'in_stock' => true,
        'is_active' => true,
        'is_in_yml_feed' => true,
        'description' => '<p>Сверлильный <strong>станок</strong> для мастерской.</p>',
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $this->artisan('feeds:generate-market')->assertSuccessful();

    $xml = Storage::disk('public')->get('feeds/yandex-market.xml');

    expect($xml)
        ->toContain('<description>Сверлильный станок для мастерской.</description>');
});
