<?php

use App\Filament\Resources\LegacyProducts\LegacyProductResource;
use App\Filament\Resources\LegacyProducts\Pages\ListLegacyProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\LegacyProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\NameNormalizer;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

test('legacy product resource is placed after products without navigation group', function (): void {
    expect(ProductResource::getNavigationSort())->toBe(1)
        ->and(LegacyProductResource::getNavigationSort())->toBe(2)
        ->and(LegacyProductResource::getNavigationGroup())->toBeNull()
        ->and(LegacyProductResource::getNavigationLabel())->toBe('KratonKuban товары')
        ->and(LegacyProductResource::getPluralModelLabel())->toBe('KratonKuban товары');
});

test('it manually matches a legacy product from the legacy products resource', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createLegacyResourceProduct([
        'name' => 'Товар для общего legacy resource',
        'slug' => 'legacy-resource-product',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/resource-manual-match.php',
        'name' => 'Legacy товар resource',
        'sku' => 'RESOURCE-MATCH',
    ]);

    Livewire::test(ListLegacyProducts::class)
        ->callAction(TestAction::make('matchManually')->table($legacyProduct), [
            'product_id' => $product->id,
        ])
        ->assertNotified();

    assertDatabaseHas('legacy_products', [
        'id' => $legacyProduct->id,
        'matched_product_id' => $product->id,
        'match_strategy' => 'manual',
        'match_source' => 'manual',
        'match_locked' => true,
        'matched_by_user_id' => $user->id,
        'redirect_enabled' => true,
    ]);
});

test('it manually removes a legacy product match from the legacy products resource', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createLegacyResourceProduct([
        'name' => 'Товар для отвязки resource',
        'slug' => 'legacy-resource-remove-product',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/resource-manual-remove.php',
        'name' => 'Legacy товар remove resource',
        'sku' => 'RESOURCE-REMOVE',
        'matched_product_id' => $product->id,
        'match_strategy' => 'manual',
        'match_source' => 'manual',
        'match_locked' => true,
        'redirect_enabled' => true,
    ]);

    Livewire::test(ListLegacyProducts::class)
        ->callAction(TestAction::make('removeMatch')->table($legacyProduct))
        ->assertNotified();

    assertDatabaseHas('legacy_products', [
        'id' => $legacyProduct->id,
        'matched_product_id' => null,
        'match_strategy' => 'manual_removed',
        'match_source' => 'manual',
        'match_locked' => true,
        'matched_by_user_id' => $user->id,
        'redirect_enabled' => false,
    ]);
});

test('it bulk matches legacy products from the legacy products resource', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createLegacyResourceProduct([
        'name' => 'Товар для массовой привязки',
        'slug' => 'legacy-resource-bulk-match-product',
    ]);

    $firstLegacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/resource-bulk-match-first.php',
        'name' => 'Legacy bulk first',
        'sku' => 'BULK-FIRST',
    ]);

    $secondLegacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/resource-bulk-match-second.php',
        'name' => 'Legacy bulk second',
        'sku' => 'BULK-SECOND',
    ]);

    Livewire::test(ListLegacyProducts::class)
        ->callTableBulkAction('bulkMatchManually', [$firstLegacyProduct, $secondLegacyProduct], [
            'product_id' => $product->id,
        ])
        ->assertNotified();

    foreach ([$firstLegacyProduct, $secondLegacyProduct] as $legacyProduct) {
        assertDatabaseHas('legacy_products', [
            'id' => $legacyProduct->id,
            'matched_product_id' => $product->id,
            'match_strategy' => 'manual',
            'match_source' => 'manual',
            'match_locked' => true,
            'matched_by_user_id' => $user->id,
            'redirect_enabled' => true,
        ]);
    }
});

test('it bulk removes legacy product matches from the legacy products resource', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createLegacyResourceProduct([
        'name' => 'Товар для массовой отвязки',
        'slug' => 'legacy-resource-bulk-remove-product',
    ]);

    $firstLegacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/resource-bulk-remove-first.php',
        'name' => 'Legacy bulk remove first',
        'sku' => 'BULK-REMOVE-FIRST',
        'matched_product_id' => $product->id,
        'match_strategy' => 'manual',
        'match_source' => 'manual',
        'match_locked' => true,
        'redirect_enabled' => true,
    ]);

    $secondLegacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/resource-bulk-remove-second.php',
        'name' => 'Legacy bulk remove second',
        'sku' => 'BULK-REMOVE-SECOND',
        'matched_product_id' => $product->id,
        'match_strategy' => 'manual',
        'match_source' => 'manual',
        'match_locked' => true,
        'redirect_enabled' => true,
    ]);

    Livewire::test(ListLegacyProducts::class)
        ->callTableBulkAction('bulkRemoveMatch', [$firstLegacyProduct, $secondLegacyProduct])
        ->assertNotified();

    foreach ([$firstLegacyProduct, $secondLegacyProduct] as $legacyProduct) {
        assertDatabaseHas('legacy_products', [
            'id' => $legacyProduct->id,
            'matched_product_id' => null,
            'match_strategy' => 'manual_removed',
            'match_source' => 'manual',
            'match_locked' => true,
            'matched_by_user_id' => $user->id,
            'redirect_enabled' => false,
        ]);
    }
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createLegacyResourceProduct(array $attributes = []): Product
{
    $attributes = array_merge([
        'name' => 'Legacy resource product',
        'slug' => 'legacy-resource-product-'.fake()->unique()->slug(),
        'sku' => 'LEGACY-RESOURCE-'.fake()->unique()->bothify('###'),
        'price_amount' => 1000,
        'currency' => 'RUB',
        'is_active' => true,
    ], $attributes);

    $attributes['name_normalized'] = NameNormalizer::normalize($attributes['name']);

    return Product::query()->create($attributes);
}
