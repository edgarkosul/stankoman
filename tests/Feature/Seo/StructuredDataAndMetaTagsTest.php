<?php

use App\Filament\Pages\HomePage as HomePageSettingsPage;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

it('renders organization and website schema with social meta tags on the home page', function (): void {
    config()->set('company.legal_name', 'ООО Тестовая компания');
    config()->set('company.brand_line', 'Test Brand');
    config()->set('company.site_url', 'https://settings.example.com');
    config()->set('company.phone', '+7 (999) 123-45-67');
    config()->set('company.public_email', 'public@example.com');

    Page::factory()->create([
        'slug' => 'home',
        'title' => 'Главная',
        'meta_title' => 'Главная страница каталога',
        'meta_description' => 'Главная страница каталога промышленного оборудования.',
        'content' => '<p>Каталог станков и промышленного оборудования для производства.</p>',
        'is_published' => true,
    ]);

    $response = $this->get(route('home'));

    $response->assertSuccessful()
        ->assertSee('<title>Главная страница каталога | '.config('app.name').'</title>', false)
        ->assertSee('<meta name="description" content="Главная страница каталога промышленного оборудования." />', false)
        ->assertSee('<meta property="og:type" content="website" />', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image" />', false)
        ->assertSee('"@type": "Organization"', false)
        ->assertSee('"legalName": "ООО Тестовая компания"', false)
        ->assertSee('"alternateName": "Test Brand"', false)
        ->assertSee('"url": "https://settings.example.com"', false)
        ->assertSee('"telephone": "+7 (999) 123-45-67"', false)
        ->assertSee('"email": "public@example.com"', false)
        ->assertSee('"contactType": "customer support"', false)
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

it('uses generated product title fallback and cleaned canonical url when seo fields are absent', function (): void {
    $product = Product::query()->create([
        'name' => 'Станок фрезерный TEST-200',
        'slug' => 'stanok-frezernyj-test-200',
        'price_amount' => 125000,
        'currency' => 'RUB',
        'is_active' => true,
        'in_stock' => true,
        'description' => '<p>Фрезерный станок для точной обработки металла и заготовок.</p>',
    ]);

    $response = $this->get(route('product.show', ['product' => $product]).'?utm_source=test&gclid=demo');

    $response->assertSuccessful()
        ->assertSee('<title>Купить Станок фрезерный TEST-200 по цене 125 000 ₽ | '.config('app.name').'</title>', false)
        ->assertSee(
            '<link rel="canonical" href="'.route('product.show', ['product' => $product]).'" />',
            false,
        );
});

it('renders category meta title and description on catalog pages', function (): void {
    $category = Category::query()->create([
        'name' => 'Ленточнопильные станки',
        'slug' => 'lentochnopilnye-stanki-seo',
        'parent_id' => Category::defaultParentKey(),
        'order' => 999,
        'is_active' => true,
        'meta_title' => 'Купить ленточнопильные станки',
        'meta_description' => 'Подборка ленточнопильных станков с доставкой и сервисом.',
    ]);

    Category::query()->create([
        'name' => 'Ручные модели',
        'slug' => 'ruchnye-modeli-seo',
        'parent_id' => $category->id,
        'order' => 1,
        'is_active' => true,
    ]);

    $response = $this->get(route('catalog.leaf', ['path' => $category->slug]));

    $response->assertSuccessful()
        ->assertSee('<title>Купить ленточнопильные станки | '.config('app.name').'</title>', false)
        ->assertSee('<meta name="description" content="Подборка ленточнопильных станков с доставкой и сервисом." />', false);
});

it('renders yandex metrika assets on the home page', function (): void {
    Page::factory()->create([
        'slug' => 'home',
        'title' => 'Главная',
        'content' => '<p>Главная страница</p>',
        'is_published' => true,
    ]);

    $response = $this->get(route('home'));

    $response->assertSuccessful()
        ->assertSee('<meta name="yandex-metrika-id" content="108565390">', false)
        ->assertSee("https://mc.yandex.ru/metrika/tag.js?id=108565390', 'ym');", false)
        ->assertSee("ym(108565390, 'init', {", false)
        ->assertSee("ecommerce: 'dataLayer',", false)
        ->assertSee('referrer: document.referrer,', false)
        ->assertSee('url: location.href,', false)
        ->assertDontSee('triggerEvent: true,', false)
        ->assertDontSee('window.yandexMetrikaCounterId = 108565390;', false)
        ->assertSee('https://mc.yandex.ru/watch/108565390', false);
});

it('saves home page seo fields from the admin page', function (): void {
    $user = User::factory()->create();
    $page = Page::factory()->create([
        'slug' => 'home',
        'title' => 'Главная',
        'meta_title' => 'Старый SEO title',
        'meta_description' => 'Старое SEO описание',
    ]);

    $this->actingAs($user);

    Livewire::test(HomePageSettingsPage::class)
        ->set('data.content', '<p>Обновленный контент</p>')
        ->set('data.meta_title', 'Новый SEO title главной')
        ->set('data.meta_description', 'Новое SEO описание главной страницы')
        ->call('save');

    expect($page->fresh()->meta_title)->toBe('Новый SEO title главной')
        ->and($page->fresh()->meta_description)->toBe('Новое SEO описание главной страницы');
});
