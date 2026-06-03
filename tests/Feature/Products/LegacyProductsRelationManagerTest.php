<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\LegacyProductsRelationManager;
use App\Models\LegacyProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\NameNormalizer;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

test('it manually matches a legacy product from product edit relation manager', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createRelationManagerProduct([
        'name' => 'Intertooler товар',
        'slug' => 'intertooler-manual-match-product',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/manual-ui-match.php',
        'name' => 'Legacy товар',
        'sku' => 'LEGACY-UI',
    ]);

    Livewire::test(LegacyProductsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('addLegacyMatch')->table(), [
            'legacy_product_id' => $legacyProduct->id,
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

test('it manually removes a legacy product match from product edit relation manager', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = createRelationManagerProduct([
        'name' => 'Intertooler товар для отвязки',
        'slug' => 'intertooler-manual-remove-product',
    ]);

    $legacyProduct = LegacyProduct::query()->create([
        'source_site' => 'kratonkuban.ru',
        'source_path' => '/manual-ui-remove.php',
        'name' => 'Legacy товар для отвязки',
        'sku' => 'LEGACY-REMOVE-UI',
        'matched_product_id' => $product->id,
        'match_strategy' => 'manual',
        'match_source' => 'manual',
        'match_locked' => true,
        'redirect_enabled' => true,
    ]);

    Livewire::test(LegacyProductsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('removeLegacyMatch')->table($legacyProduct))
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

/**
 * @param  array<string, mixed>  $attributes
 */
function createRelationManagerProduct(array $attributes = []): Product
{
    $attributes = array_merge([
        'name' => 'Relation manager product',
        'slug' => 'relation-manager-product-'.fake()->unique()->slug(),
        'sku' => 'RELATION-'.fake()->unique()->bothify('###'),
        'price_amount' => 1000,
        'currency' => 'RUB',
        'is_active' => true,
    ], $attributes);

    $attributes['name_normalized'] = NameNormalizer::normalize($attributes['name']);

    return Product::query()->create($attributes);
}
