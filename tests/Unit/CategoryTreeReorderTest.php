<?php

use App\Filament\Pages\CategoryTree;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('category tree reorder moves hidden staging category away from conflicting root order', function (): void {
    $this->actingAs(User::factory()->create());

    $firstVisibleRoot = Category::query()->create([
        'name' => 'Первая видимая',
        'slug' => 'first-visible-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    $hiddenStagingRoot = Category::query()->create([
        'name' => 'Служебная staging',
        'slug' => Category::stagingSlug(),
        'parent_id' => Category::defaultParentKey(),
        'order' => 2,
        'is_active' => true,
    ]);

    $secondVisibleRoot = Category::query()->create([
        'name' => 'Вторая видимая',
        'slug' => 'second-visible-root',
        'parent_id' => Category::defaultParentKey(),
        'order' => 3,
        'is_active' => true,
    ]);

    Livewire::test(CategoryTree::class)
        ->call('updateTree', [
            [
                'id' => $secondVisibleRoot->id,
                'children' => [],
            ],
            [
                'id' => $firstVisibleRoot->id,
                'children' => [],
            ],
        ]);

    expect($secondVisibleRoot->fresh()?->order)->toBe(1)
        ->and($firstVisibleRoot->fresh()?->order)->toBe(2)
        ->and($hiddenStagingRoot->fresh()?->order)->toBeGreaterThan(2);
});
