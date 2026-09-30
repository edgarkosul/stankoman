<?php

use App\Models\Page;
use App\Services\Kb\HtmlKbTextExtractor;
use App\Shop\PageKbSource;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    // Страница из белого списка ставит переиндексацию в очередь ассистента,
    // а это Redis, а не sync из phpunit.xml: без подделки задача ушла бы
    // в живую очередь дева.
    Queue::fake();
});

$source = fn (array $slugs): PageKbSource => new PageKbSource(app(HtmlKbTextExtractor::class), $slugs, 'InterTooler.ru');

$keys = fn (PageKbSource $source): array => array_map(
    static fn ($document): string => $document->key,
    iterator_to_array($source->documents(), false),
);

it('отдаёт только страницы из белого списка и только опубликованные', function () use ($source, $keys): void {
    Page::factory()->create(['slug' => 'dostavka-i-oplata', 'content' => '<p>Везём по всей России.</p>', 'is_published' => true]);
    Page::factory()->create(['slug' => 'kontakty', 'content' => '<p>Краснодар.</p>', 'is_published' => false]);
    Page::factory()->create(['slug' => 'karera', 'content' => '<p>Ищем менеджера.</p>', 'is_published' => true]);

    // Неопубликованная страница отвечает 404 — бот не должен давать на неё ссылку.
    expect($keys($source(['dostavka-i-oplata', 'kontakty'])))->toBe(['dostavka-i-oplata']);
});

it('пропускает пустую страницу', function () use ($source, $keys): void {
    Page::factory()->create(['slug' => 'servisnyi-centr', 'content' => '<p></p>', 'is_published' => true]);

    expect($keys($source(['servisnyi-centr'])))->toBe([]);
});

it('даёт ссылку на страницу и крошки с названием магазина', function () use ($source): void {
    Page::factory()->create([
        'slug' => 'dostavka-i-oplata',
        'title' => 'Доставка и оплата',
        'content' => '<p><strong>Основные способы доставки:</strong></p><ul><li><p>Доставка СДЭК</p></li></ul>',
        'is_published' => true,
    ]);

    $document = iterator_to_array($source(['dostavka-i-oplata'])->documents(), false)[0];

    expect($document->url)->toBe(route('page.show', ['page' => 'dostavka-i-oplata']))
        ->and($document->title)->toBe('Доставка и оплата')
        ->and($document->breadcrumb)->toBe(['InterTooler.ru', 'Доставка и оплата'])
        ->and($document->text)->toBe("Основные способы доставки:\n\n- Доставка СДЭК");
});

it('с пустым белым списком не отдаёт ничего', function () use ($source, $keys): void {
    Page::factory()->create(['slug' => 'dostavka-i-oplata', 'content' => '<p>Везём.</p>', 'is_published' => true]);

    expect($keys($source([])))->toBe([]);
});
