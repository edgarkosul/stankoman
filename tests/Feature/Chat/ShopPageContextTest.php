<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;
use Siteko\Chatbot\Ai\Tools\ToolContext;
use Siteko\Chatbot\Chat\Contracts\PageContextSource;

/*
 * Контекст страницы на живых моделях. Главное здесь — цена: в контексте
 * она обязана быть ровно той, что крупно стоит на карточке у этого
 * посетителя. Гостю сумма скидки для зарегистрированных не показывается
 * (DiscountVisibility), и бот не должен узнать её из контекста страницы
 * в обход карточки.
 */

beforeEach(function (): void {
    // Страницы из базы знаний бота ставят переиндексацию на redis-assistant
    // мимо QUEUE_CONNECTION=sync.
    Queue::fake();
});

function chatShopProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'name' => 'Станок ленточнопильный IT-4500',
        'slug' => 'stanok-lentochnopilnyi-it-4500',
        'sku' => 'IT-4500',
        'brand' => 'InterTooler',
        'price_amount' => 108596,
        'discount_price' => 103166,
        'currency' => 'RUB',
        'in_stock' => true,
        'is_active' => true,
    ], $overrides));
}

function chatPages(): PageContextSource
{
    return app(PageContextSource::class);
}

it('называет гостю цену с карточки, а не цену для зарегистрированных', function (): void {
    $product = chatShopProduct();

    $guest = chatPages()->describe(['type' => 'product', 'slug' => $product->slug], [ToolContext::SEES_MEMBER_PRICES => false]);
    $member = chatPages()->describe(['type' => 'product', 'slug' => $product->slug], [ToolContext::SEES_MEMBER_PRICES => true]);

    expect($guest)->toMatchArray([
        'type' => 'product',
        'id' => $product->id,
        'name' => 'Станок ленточнопильный IT-4500',
        'availability' => 'в наличии',
        'url' => route('product.show', $product),
    ])
        ->and($guest['price_label'])->toContain('108 596 руб.')
        ->and($guest['price_label'])->not->toContain('103 166')
        ->and($member['price_label'])->toContain('103 166 руб.');
});

it('не подменяет снятый с продажи товар похожим по обозначению', function (): void {
    chatShopProduct(['slug' => 'it-4500', 'is_active' => false]);
    chatShopProduct(['slug' => 'drugoi-stanok', 'name' => 'Станок IT-4500 NEW', 'sku' => 'IT-4500-N']);

    expect(chatPages()->describe(['type' => 'product', 'slug' => 'it-4500'], [ToolContext::SEES_MEMBER_PRICES => false]))->toBeNull()
        ->and(chatPages()->describe(['type' => 'product', 'slug' => 'net-takogo'], [ToolContext::SEES_MEMBER_PRICES => false]))->toBeNull();
});

it('разворачивает путь раздела в хлебные крошки так же, как страница раздела', function (): void {
    $root = Category::query()->create([
        'name' => 'Станки',
        'slug' => 'stanki',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);
    Category::query()->create([
        'name' => 'Ленточнопильные',
        'slug' => 'lentochnopilnye',
        'parent_id' => $root->id,
        'order' => 1,
        'is_active' => true,
    ]);

    expect(chatPages()->describe(['type' => 'category', 'path' => 'stanki/lentochnopilnye'], [ToolContext::SEES_MEMBER_PRICES => false]))
        ->toMatchArray([
            'type' => 'category',
            'name' => 'Ленточнопильные',
            'breadcrumb' => 'Станки → Ленточнопильные',
            'url' => route('catalog.leaf', ['path' => 'stanki/lentochnopilnye']),
        ])
        // Слаг раздела без родителя — не этот раздел.
        ->and(chatPages()->describe(['type' => 'category', 'path' => 'lentochnopilnye'], [ToolContext::SEES_MEMBER_PRICES => false]))->toBeNull();
});

it('называет только опубликованную страницу', function (): void {
    Page::factory()->create(['slug' => 'dostavka-i-oplata', 'title' => 'Доставка и оплата', 'is_published' => true]);
    Page::factory()->create(['slug' => 'chernovik', 'title' => 'Черновик', 'is_published' => false]);

    expect(chatPages()->describe(['type' => 'page', 'slug' => 'dostavka-i-oplata'], [ToolContext::SEES_MEMBER_PRICES => false]))
        ->toMatchArray(['type' => 'page', 'title' => 'Доставка и оплата', 'url' => route('page.show', 'dostavka-i-oplata')])
        ->and(chatPages()->describe(['type' => 'page', 'slug' => 'chernovik'], [ToolContext::SEES_MEMBER_PRICES => false]))->toBeNull();
});

it('узнаёт страницу по адресу — так её присылает панель чата из рамки', function (): void {
    // Панель пакета живёт в /chat/panel и знает о странице посетителя только
    // `{type: page, url, title}` от лаунчера: товар и раздел — по маршрутам сайта.
    $product = chatShopProduct();

    $guest = chatPages()->describe([
        'type' => 'page',
        'url' => 'https://intertooler.ru/product/'.$product->slug.'?utm_source=x',
        'title' => 'Компрессор — купить',
    ], [ToolContext::SEES_MEMBER_PRICES => false]);

    expect($guest['type'])->toBe('product')
        ->and($guest['name'])->toBe($product->name)
        ->and($guest['price_label'])->not->toBe('');

    $home = chatPages()->describe(['type' => 'page', 'url' => 'https://intertooler.ru/', 'title' => 'Главная']);
    $unknown = ['type' => 'page', 'url' => 'https://intertooler.ru/cart', 'title' => 'Корзина'];

    expect($home)->toBe(['type' => 'home'])
        // Не наша страница из белого списка — остаётся то, что прислали:
        // адрес с заголовком тоже контекст.
        ->and(chatPages()->describe($unknown))->toBe($unknown)
        ->and(chatPages()->describe(['type' => 'page', 'url' => 'https://intertooler.ru/product/net-takogo', 'title' => 'x']))
        ->toBe(['type' => 'page', 'url' => 'https://intertooler.ru/product/net-takogo', 'title' => 'x']);
});
