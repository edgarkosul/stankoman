<?php

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\RelationManagers\AttributeDefsRelationManager;
use App\Models\Category;
use App\Models\User;
use Filament\Actions\AttachAction;
use Livewire\Livewire;

test('attribute defs attach action preloads record select options', function (): void {
    $this->actingAs(User::factory()->create());

    $category = Category::query()->create([
        'name' => 'Листовая категория для теста фильтров',
        'slug' => 'leaf-category-attribute-defs-relation-manager-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 10,
        'is_active' => true,
    ]);

    Livewire::test(AttributeDefsRelationManager::class, [
        'ownerRecord' => $category,
        'pageClass' => EditCategory::class,
    ])->assertTableActionExists('attach', fn (AttachAction $action): bool => $action->isRecordSelectPreloaded());
});
