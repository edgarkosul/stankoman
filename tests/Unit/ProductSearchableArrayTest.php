<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

uses(TestCase::class);

it('adds numeric aliases for prefixed model codes to searchable data', function (): void {
    $product = new Product([
        'name' => 'Рейсмусовый станок Warrior W0201D 230В',
        'sku' => 'AB-0201',
        'brand' => 'Warrior',
        'price_amount' => 341700,
        'discount_price' => 307530,
    ]);

    $searchableData = $product->toSearchableArray();

    expect($searchableData['search_terms'])
        ->toContain('w0201d')
        ->toContain('0201d')
        ->toContain('0201')
        ->toContain('ab0201')
        ->not->toContain('230');
});

it('keeps aliases for hyphenated model codes without indexing standalone numeric suffixes', function (): void {
    $product = new Product([
        'name' => 'Фуговальный станок JWP-201 230В',
        'brand' => 'Jet',
        'price_amount' => 1000,
    ]);

    $searchableData = $product->toSearchableArray();

    expect($searchableData['search_terms'])
        ->toContain('jwp201')
        ->toContain('201')
        ->not->toContain('230');
});

it('puts categories, stock and popularity into the document', function (): void {
    $product = new Product([
        'name' => 'Компрессор винтовой',
        'slug' => 'kompressor-vintovoy',
        'sku' => 'VK-15',
        'brand' => 'Hansmann',
        'price_amount' => 250000,
        'in_stock' => true,
        'popularity' => 7,
    ]);

    /*
     * Категории кладём связью, как их кладёт индексация: в документе они
     * нужны ради фильтра по разделу — до этой правки поле объявлялось
     * фильтруемым в config/scout.php, но в документ не попадало вовсе,
     * и фильтр молча не работал. Заодно то же самое было с in_stock
     * и popularity.
     */
    $product->setRelation('categories', new EloquentCollection([
        (new Category)->forceFill(['id' => 12, 'name' => 'Компрессоры']),
        (new Category)->forceFill(['id' => 34, 'name' => 'Винтовые компрессоры']),
    ]));

    $document = $product->toSearchableArray();

    expect($document['category_ids'])->toBe([12, 34])
        ->and($document['category_names'])->toBe(['Компрессоры', 'Винтовые компрессоры'])
        ->and($document['slug'])->toBe('kompressor-vintovoy')
        ->and($document['in_stock'])->toBeTrue()
        ->and($document['popularity'])->toBe(7);
});

it('builds a document for an unsaved product without touching the database', function (): void {
    $document = (new Product(['name' => 'Черновик']))->toSearchableArray();

    expect($document['category_ids'])->toBe([])
        ->and($document['category_names'])->toBe([]);
});
