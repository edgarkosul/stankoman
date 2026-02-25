<?php

use App\Filament\Resources\Attributes\AttributeResource;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\AttributeValuesRelationManager;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

test('relation manager saves numeric value entered in additional unit as base unit', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::query()->create([
        'name' => 'Товар для unit-конвертации',
        'slug' => 'product-unit-conversion-test',
        'price_amount' => 12000,
    ]);

    $category = Category::query()->create([
        'name' => 'Категория unit-конвертации',
        'slug' => 'category-unit-conversion-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 11,
        'is_active' => true,
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $meter = Unit::query()->create([
        'name' => 'Метр',
        'symbol' => 'м',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $centimeter = Unit::query()->create([
        'name' => 'Сантиметр',
        'symbol' => 'см',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 0.01,
        'si_offset' => 0,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Длина',
        'slug' => 'length-unit-conversion-test',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'unit_id' => $meter->id,
        'dimension' => 'length',
        'is_filterable' => true,
    ]);

    $attribute->units()->attach($meter->id, ['is_default' => true, 'sort_order' => 0]);
    $attribute->units()->attach($centimeter->id, ['is_default' => false, 'sort_order' => 1]);

    $category->attributeDefs()->attach($attribute->id, [
        'display_unit_id' => $meter->id,
        'visible_in_specs' => true,
        'visible_in_compare' => true,
    ]);

    Livewire::test(AttributeValuesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'attribute_id' => $attribute->id,
            'input_unit_id' => $centimeter->id,
            'value_number' => '250',
        ])
        ->assertNotified();

    $savedValue = ProductAttributeValue::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $attribute->id)
        ->first();

    expect($savedValue)->not->toBeNull()
        ->and((float) $savedValue->value_number)->toEqualWithDelta(2.5, 0.000001);
});

test('relation manager shows ui default unit info based on attribute default unit', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::query()->create([
        'name' => 'Товар для отображения defaultUnit',
        'slug' => 'product-default-unit-info-test',
        'price_amount' => 14000,
    ]);

    $category = Category::query()->create([
        'name' => 'Категория defaultUnit',
        'slug' => 'category-default-unit-info-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 12,
        'is_active' => true,
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $meter = Unit::query()->create([
        'name' => 'Метр',
        'symbol' => 'м',
        'dimension' => 'length',
        'base_symbol' => 'm',
        'si_factor' => 1,
        'si_offset' => 0,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Высота',
        'slug' => 'height-default-unit-info-test',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'unit_id' => $meter->id,
        'dimension' => 'length',
        'is_filterable' => true,
    ]);

    $component = Livewire::test(AttributeValuesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->instance();

    $method = new ReflectionMethod(AttributeValuesRelationManager::class, 'uiDefaultUnitInfoForAttribute');
    $method->setAccessible(true);

    $info = (string) $method->invoke($component, $attribute->id);

    expect($info)
        ->toContain('UI-единица (defaultUnit):')
        ->toContain('Метр')
        ->toContain('(м)');
});

test('relation manager attribute name column links to attribute edit page', function (): void {
    $this->actingAs(User::factory()->create());

    $product = Product::query()->create([
        'name' => 'Товар для ссылки на редактирование атрибута',
        'slug' => 'product-attribute-edit-link-relation-manager-test',
        'price_amount' => 16000,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Ссылка на атрибут',
        'slug' => 'attribute-edit-link-relation-manager-test',
        'data_type' => 'text',
        'value_source' => 'free',
        'input_type' => 'text',
        'is_filterable' => true,
    ]);

    $attributeValue = ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'attribute_id' => $attribute->id,
        'value_text' => 'Тестовое значение',
    ]);

    $expectedUrl = AttributeResource::getUrl('edit', ['record' => $attribute->id]);

    Livewire::test(AttributeValuesRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->assertCanSeeTableRecords([$attributeValue])
        ->assertTableColumnExists(
            'attribute.name',
            fn (TextColumn $column): bool => $column->getUrl() === $expectedUrl,
            $attributeValue
        );
});
