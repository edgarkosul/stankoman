<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Services\Chat\Contracts\PageContextSource;

/*
 * Контекст страницы на живых моделях. Главное здесь — цена: в контексте
 * она обязана быть ровно той, что крупно стоит на карточке у этого
 * посетителя. Гостю сумма скидки для зарегистрированных не показывается
 * (DiscountVisibility), и бот не должен узнать её из контекста страницы
 * в обход карточки.
 */

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

    $guest = chatPages()->describe(['type' => 'product', 'slug' => $product->slug], seesDiscounts: false);
    $member = chatPages()->describe(['type' => 'product', 'slug' => $product->slug], seesDiscounts: true);

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

    expect(chatPages()->describe(['type' => 'product', 'slug' => 'it-4500'], seesDiscounts: false))->toBeNull()
        ->and(chatPages()->describe(['type' => 'product', 'slug' => 'net-takogo'], seesDiscounts: false))->toBeNull();
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

    expect(chatPages()->describe(['type' => 'category', 'path' => 'stanki/lentochnopilnye'], seesDiscounts: false))
        ->toMatchArray([
            'type' => 'category',
            'name' => 'Ленточнопильные',
            'breadcrumb' => 'Станки → Ленточнопильные',
            'url' => route('catalog.leaf', ['path' => 'stanki/lentochnopilnye']),
        ])
        // Слаг раздела без родителя — не этот раздел.
        ->and(chatPages()->describe(['type' => 'category', 'path' => 'lentochnopilnye'], seesDiscounts: false))->toBeNull();
});

it('называет только опубликованную страницу', function (): void {
    Page::factory()->create(['slug' => 'dostavka-i-oplata', 'title' => 'Доставка и оплата', 'is_published' => true]);
    Page::factory()->create(['slug' => 'chernovik', 'title' => 'Черновик', 'is_published' => false]);

    expect(chatPages()->describe(['type' => 'page', 'slug' => 'dostavka-i-oplata'], seesDiscounts: false))
        ->toMatchArray(['type' => 'page', 'title' => 'Доставка и оплата', 'url' => route('page.show', 'dostavka-i-oplata')])
        ->and(chatPages()->describe(['type' => 'page', 'slug' => 'chernovik'], seesDiscounts: false))->toBeNull();
});
