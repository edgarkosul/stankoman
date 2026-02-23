<?php

use App\Filament\Pages\CategoryTree;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\User;
use Livewire\Livewire;

test('list categories table hides staging category', function (): void {
    $this->actingAs(User::factory()->create());

    $visibleCategory = Category::query()->create([
        'name' => 'Видимая категория',
        'slug' => 'visible-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $stagingCategory = Category::query()->create([
        'name' => 'Staging',
        'slug' => Category::stagingSlug(),
        'parent_id' => $visibleCategory->id,
        'order' => 99,
        'is_active' => true,
    ]);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords([$visibleCategory])
        ->assertCanNotSeeTableRecords([$stagingCategory]);
});

test('category tree query and parent options hide staging category', function (): void {
    $visibleCategory = Category::query()->create([
        'name' => 'Обычная категория',
        'slug' => 'regular-category',
        'parent_id' => Category::defaultParentKey(),
        'order' => 10,
        'is_active' => true,
    ]);

    $stagingCategory = Category::query()->create([
        'name' => 'Staging',
        'slug' => Category::stagingSlug(),
        'parent_id' => Category::defaultParentKey(),
        'order' => 11,
        'is_active' => true,
    ]);

    $queryMethod = new ReflectionMethod(CategoryTree::class, 'getTreeQuery');
    $queryMethod->setAccessible(true);
    $treeQuery = $queryMethod->invoke(app(CategoryTree::class));

    expect($treeQuery->pluck('id')->all())
        ->toContain($visibleCategory->id)
        ->not->toContain($stagingCategory->id);

    $optionsMethod = new ReflectionMethod(CategoryTree::class, 'categoryOptions');
    $optionsMethod->setAccessible(true);
    $options = $optionsMethod->invoke(null);

    expect($options)
        ->toHaveKey((string) Category::defaultParentKey())
        ->and(array_key_exists($visibleCategory->id, $options))->toBeTrue()
        ->and(array_key_exists($stagingCategory->id, $options))->toBeFalse();
});

test('staging category is always normalized as imported root category', function (): void {
    $parentCategory = Category::query()->create([
        'name' => 'Parent category',
        'slug' => 'parent-category-for-staging',
        'parent_id' => Category::defaultParentKey(),
        'order' => 20,
        'is_active' => true,
    ]);

    $stagingCategory = Category::query()->create([
        'name' => 'Random name',
        'slug' => Category::stagingSlug(),
        'parent_id' => $parentCategory->id,
        'order' => 200,
        'is_active' => true,
    ]);

    $stagingCategory->update([
        'name' => 'Another name',
        'parent_id' => $parentCategory->id,
    ]);

    $stagingCategory->refresh();

    expect($stagingCategory->name)->toBe(Category::stagingName())
        ->and($stagingCategory->parent_id)->toBe(Category::defaultParentKey());
});
