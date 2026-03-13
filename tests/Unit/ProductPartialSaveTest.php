<?php

use App\Models\Product;
use App\Support\NameNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Schema::dropIfExists('products');

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized');
        $table->string('slug')->unique();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->unsignedInteger('popularity')->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->boolean('with_dns')->default(true);
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('products');
});

it('preserves name_normalized when saving a partially loaded product', function () {
    $product = Product::query()->create([
        'name' => 'Товар 1',
        'slug' => 'partial-save-product',
        'price_amount' => 1000,
    ]);

    $partialProduct = Product::query()
        ->select(['id', 'price_amount'])
        ->findOrFail($product->id);

    $partialProduct->update([
        'discount_price' => 900,
    ]);

    $product->refresh();

    expect($product->discount_price)->toBe(900)
        ->and($product->name_normalized)->toBe(NameNormalizer::normalize('Товар 1'));
});
