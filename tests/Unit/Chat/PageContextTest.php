<?php

use App\Services\Ai\Contracts\ProductLookup;
use App\Services\Chat\PageContext;
use App\Shop\ShopPageContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/*
 * Проверяется белый список маршрутов и то, что уезжает в промпт: обе половины
 * работают без базы. Разворот локатора в карточку — уже запросы, он в Feature.
 */

function chatRequestOn(string $name, array $parameters = [], string $uri = '/'): Request
{
    $route = new Route(['GET'], $uri, ['as' => $name]);
    $route->parameters = $parameters;

    $request = Request::create($uri);
    $request->setRouteResolver(fn (): Route => $route);

    return $request;
}

function chatPageContext(): ShopPageContext
{
    return new ShopPageContext(Mockery::mock(ProductLookup::class));
}

it('узнаёт каталог и информационные страницы', function (): void {
    $pages = chatPageContext();

    expect($pages->locate(chatRequestOn('home')))->toBe(['type' => 'home'])
        ->and($pages->locate(chatRequestOn('product.show', ['product' => 'it-4500'])))
        ->toBe(['type' => 'product', 'slug' => 'it-4500'])
        ->and($pages->locate(chatRequestOn('catalog.leaf', ['path' => 'stanki/lentochnopilnye/'])))
        ->toBe(['type' => 'category', 'path' => 'stanki/lentochnopilnye'])
        ->and($pages->locate(chatRequestOn('catalog.leaf', ['path' => ''])))
        ->toBe(['type' => 'catalog'])
        ->and($pages->locate(chatRequestOn('page.show', ['page' => 'dostavka-i-oplata'])))
        ->toBe(['type' => 'page', 'slug' => 'dostavka-i-oplata']);
});

it('молчит про корзину, оформление заказа, личный кабинет и поиск', function (): void {
    // На этих страницах на экране лежат персональные данные и содержимое
    // заказа — им в переписке со шлюзом делать нечего.
    foreach (['cart.index', 'checkout.index', 'user.orders.show', 'favorites.index', 'search'] as $name) {
        expect(chatPageContext()->locate(chatRequestOn($name)))->toBeNull("маршрут {$name} не должен попадать в контекст");
    }

    expect(chatPageContext()->locate(Request::create('/')))->toBeNull();
});

it('отдаёт модели читаемые поля, а не машинные', function (): void {
    $prompt = PageContext::toPrompt([
        'type' => 'product',
        'id' => 42,
        'name' => 'Станок ленточнопильный IT-4500',
        'sku' => 'IT4500',
        'price_label' => '199 900 руб. (НДС 22% в том числе)',
        'in_stock' => true,
        'availability' => 'в наличии',
        'url' => 'https://intertooler.ru/product/it-4500',
    ]);

    expect($prompt)->toBe([
        'страница' => 'карточка товара',
        'товар' => 'Станок ленточнопильный IT-4500',
        'артикул' => 'IT4500',
        'наличие' => 'в наличии',
        'цена' => '199 900 руб. (НДС 22% в том числе)',
        'ссылка' => 'https://intertooler.ru/product/it-4500',
    ]);
});

it('не выдумывает полей, которых нет', function (): void {
    expect(PageContext::toPrompt(['type' => 'product', 'name' => 'Генератор']))
        ->toBe(['страница' => 'карточка товара', 'товар' => 'Генератор'])
        ->and(PageContext::toPrompt(['type' => 'category', 'breadcrumb' => 'Станки → Ленточнопильные']))
        ->toBe(['страница' => 'раздел каталога', 'раздел' => 'Станки → Ленточнопильные'])
        ->and(PageContext::toPrompt(['type' => 'catalog']))->toBe(['страница' => 'каталог магазина'])
        ->and(PageContext::toPrompt(null))->toBeNull()
        ->and(PageContext::toPrompt(['type' => 'unknown']))->toBeNull();
});
