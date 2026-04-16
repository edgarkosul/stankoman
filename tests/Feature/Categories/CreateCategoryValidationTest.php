<?php

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->create();

    config([
        'settings.general.filament_admin_emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);
});

it('rejects duplicate category slugs within the same parent', function (): void {
    Category::query()->create([
        'name' => 'Мусор',
        'slug' => 'musor',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'parent_id' => Category::defaultParentKey(),
            'name' => 'Мусор',
            'slug' => 'musor',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Category::query()
        ->where('parent_id', Category::defaultParentKey())
        ->where('slug', 'musor')
        ->count())->toBe(1);
});

it('allows the same category slug under a different parent', function (): void {
    Category::query()->create([
        'name' => 'Мусор',
        'slug' => 'musor',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $otherParent = Category::query()->create([
        'name' => 'Другой корень',
        'slug' => 'other-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'parent_id' => $otherParent->getKey(),
            'name' => 'Мусор',
            'slug' => 'musor',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::query()
        ->where('parent_id', $otherParent->getKey())
        ->where('slug', 'musor')
        ->exists())->toBeTrue();
});

it('allows creating a subcategory for parent from tree query string even if it is excluded from default parent options', function (): void {
    $leafParent = Category::query()->create([
        'name' => 'Листовая категория',
        'slug' => 'leaf-parent',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для листовой категории',
        'slug' => 'product-for-leaf-parent',
        'price_amount' => 1000,
    ]);

    $leafParent->products()->attach($product->id, ['is_primary' => true]);

    Livewire::withQueryParams(['parent_id' => $leafParent->getKey()])
        ->test(CreateCategory::class)
        ->fillForm([
            'name' => 'Новая подкатегория',
            'slug' => 'novaia-podkategoriia',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::query()
        ->where('parent_id', $leafParent->getKey())
        ->where('slug', 'novaia-podkategoriia')
        ->exists())->toBeTrue();
});

it('allows keeping the current category slug when editing', function (): void {
    $category = Category::query()->create([
        'name' => 'Мусор',
        'slug' => 'musor',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    Livewire::test(EditCategory::class, [
        'record' => $category->getRouteKey(),
    ])
        ->fillForm([
            'parent_id' => Category::defaultParentKey(),
            'name' => 'Мусор',
            'slug' => 'musor',
            'meta_title' => 'Обновлённый SEO title',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->refresh()->meta_title)->toBe('Обновлённый SEO title');
});
