<?php

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\RelationManagers\AttributeDefsRelationManager;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
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

test('category slider step cannot have more decimals than the category shows', function (): void {
    $this->actingAs(User::factory()->create());

    $category = Category::query()->create([
        'name' => 'Категория для шага ползунка',
        'slug' => 'leaf-category-slider-step-precision-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 11,
        'is_active' => true,
    ]);

    // Знаки после запятой у атрибута не заданы — действует значение по умолчанию, 2.
    $attribute = Attribute::query()->create([
        'name' => 'Мощность',
        'slug' => 'power-slider-step-precision-test',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'is_filterable' => true,
    ]);

    $category->attributeDefs()->attach($attribute->id, ['visible_in_specs' => true]);

    $relationManager = fn () => Livewire::test(AttributeDefsRelationManager::class, [
        'ownerRecord' => $category,
        'pageClass' => EditCategory::class,
    ]);

    // При шаге 0.25 и одном знаке поле «от» показало бы 9,3, а в запрос ушло бы 9,25.
    $relationManager()
        ->callAction(TestAction::make(EditAction::class)->table($attribute), [
            'number_step' => '0.25',
            'number_decimals' => '1',
        ])
        ->assertHasFormErrors(['number_step']);

    // Своих знаков у категории нет — шагу 0.125 не хватает двух знаков атрибута.
    $relationManager()
        ->callAction(TestAction::make(EditAction::class)->table($attribute), [
            'number_step' => '0.125',
            'number_decimals' => null,
        ])
        ->assertHasFormErrors(['number_step']);

    $relationManager()
        ->callAction(TestAction::make(EditAction::class)->table($attribute), [
            'number_step' => '0.25',
            'number_decimals' => '2',
        ])
        ->assertNotified();

    expect((float) DB::table('category_attribute')
        ->where('category_id', $category->id)
        ->where('attribute_id', $attribute->id)
        ->value('number_step'))->toBe(0.25);
});
