<?php

use App\Models\LegacyProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\Artisan;

test('it matches legacy products by exact sku', function (): void {
    $product = createMatchProduct([
        'name' => 'Новый товар',
        'slug' => 'new-product',
        'sku' => 'ABC-123',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/old-product.php',
        'name' => 'Старый товар',
        'sku' => 'ABC-123',
    ]);

    $exitCode = Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($exitCode)->toBe(0)
        ->and($legacyProduct->matched_product_id)->toBe($product->id)
        ->and($legacyProduct->match_strategy)->toBe('sku_exact')
        ->and($legacyProduct->match_source)->toBe('auto')
        ->and($legacyProduct->match_locked)->toBeFalse()
        ->and($legacyProduct->matched_at)->not->toBeNull()
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('it matches legacy products by normalized sku', function (): void {
    $product = createMatchProduct([
        'name' => 'Товар по нормализованному артикулу',
        'slug' => 'normalized-sku-product',
        'sku' => 'AB C_123',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/normalized-sku.php',
        'name' => 'Legacy',
        'sku' => 'abc-123',
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBe($product->id)
        ->and($legacyProduct->match_strategy)->toBe('sku_normalized')
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('it matches legacy products by normalized name when sku is empty', function (): void {
    $product = createMatchProduct([
        'name' => 'Спиральный вал Helical 150мм',
        'slug' => 'spiralny-val-helical-150mm',
        'sku' => 'CURRENT-SKU',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/name-match.php',
        'name' => '  Спиральный   вал Helical 150мм  ',
        'sku' => null,
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBe($product->id)
        ->and($legacyProduct->match_strategy)->toBe('name_normalized')
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('it does not enable redirect for ambiguous exact sku matches', function (): void {
    createMatchProduct([
        'name' => 'Первый товар',
        'slug' => 'first-duplicate-sku-product',
        'sku' => 'DUPLICATE',
    ]);

    createMatchProduct([
        'name' => 'Второй товар',
        'slug' => 'second-duplicate-sku-product',
        'sku' => 'DUPLICATE',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/ambiguous.php',
        'name' => 'Legacy ambiguous',
        'sku' => 'DUPLICATE',
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBeNull()
        ->and($legacyProduct->match_strategy)->toBeNull()
        ->and($legacyProduct->redirect_enabled)->toBeFalse();
});

test('it does not touch existing matches by default', function (): void {
    $product = createMatchProduct([
        'name' => 'Существующий матч',
        'slug' => 'existing-match-product',
        'sku' => 'CLEAR-ME',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/existing-match.php',
        'name' => 'Legacy existing',
        'sku' => 'UNKNOWN',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'match_source' => 'auto',
        'redirect_enabled' => true,
    ]);

    Artisan::call('legacy:kraton-match');

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBe($product->id)
        ->and($legacyProduct->match_strategy)->toBe('sku_exact')
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('it clears previous unlocked automatic match in refresh mode when product is no longer uniquely matched', function (): void {
    $product = createMatchProduct([
        'name' => 'Удаляемый матч',
        'slug' => 'cleared-match-product',
        'sku' => 'CLEAR-ME',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/clear-match.php',
        'name' => 'Legacy clear',
        'sku' => 'UNKNOWN',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'match_source' => 'auto',
        'match_locked' => false,
        'redirect_enabled' => true,
    ]);

    Artisan::call('legacy:kraton-match', [
        '--refresh' => true,
    ]);

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBeNull()
        ->and($legacyProduct->match_strategy)->toBeNull()
        ->and($legacyProduct->match_source)->toBeNull()
        ->and($legacyProduct->redirect_enabled)->toBeFalse();
});

test('it never changes locked manual matches', function (): void {
    $manualProduct = createMatchProduct([
        'name' => 'Ручной товар',
        'slug' => 'manual-product',
        'sku' => 'MANUAL',
    ]);

    createMatchProduct([
        'name' => 'Автоматический кандидат',
        'slug' => 'automatic-candidate',
        'sku' => 'AUTO-CANDIDATE',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/manual-match.php',
        'name' => 'Автоматический кандидат',
        'sku' => 'AUTO-CANDIDATE',
        'matched_product_id' => $manualProduct->id,
        'match_strategy' => 'manual',
        'match_source' => 'manual',
        'match_locked' => true,
        'redirect_enabled' => true,
    ]);

    Artisan::call('legacy:kraton-match', [
        '--refresh' => true,
    ]);

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBe($manualProduct->id)
        ->and($legacyProduct->match_strategy)->toBe('manual')
        ->and($legacyProduct->match_source)->toBe('manual')
        ->and($legacyProduct->match_locked)->toBeTrue()
        ->and($legacyProduct->redirect_enabled)->toBeTrue();
});

test('manual removal locks a legacy product from future automatic matching', function (): void {
    $user = User::factory()->create();
    $product = createMatchProduct([
        'name' => 'Автоматический кандидат',
        'slug' => 'manual-removal-candidate',
        'sku' => 'REMOVE-CANDIDATE',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/manual-removal.php',
        'name' => 'Автоматический кандидат',
        'sku' => 'REMOVE-CANDIDATE',
        'matched_product_id' => $product->id,
        'match_strategy' => 'sku_exact',
        'match_source' => 'auto',
        'redirect_enabled' => true,
    ]);

    $legacyProduct->removeManualMatch($user);
    Artisan::call('legacy:kraton-match', [
        '--refresh' => true,
    ]);

    $legacyProduct->refresh();

    expect($legacyProduct->matched_product_id)->toBeNull()
        ->and($legacyProduct->match_strategy)->toBe('manual_removed')
        ->and($legacyProduct->match_source)->toBe('manual')
        ->and($legacyProduct->match_locked)->toBeTrue()
        ->and($legacyProduct->matched_by_user_id)->toBe($user->id)
        ->and($legacyProduct->redirect_enabled)->toBeFalse();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createMatchProduct(array $attributes): Product
{
    $attributes = array_merge([
        'name' => 'Тестовый товар',
        'slug' => 'test-product-'.fake()->unique()->slug(),
        'sku' => 'SKU-'.fake()->unique()->bothify('###'),
        'price_amount' => 1000,
        'currency' => 'RUB',
        'is_active' => true,
    ], $attributes);

    $attributes['name_normalized'] = NameNormalizer::normalize($attributes['name']);

    return Product::query()->create($attributes);
}
