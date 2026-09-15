<?php

use App\Filament\Resources\Attributes\Pages\EditAttribute;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $user = User::factory()->create();

    config([
        'settings.general.filament_admin_emails' => [strtolower((string) $user->email)],
    ]);

    $this->actingAs($user);
});

function numberAttributeForFormatForm(): Attribute
{
    return Attribute::query()->create([
        'name' => 'Мощность',
        'slug' => 'power-attribute-number-format-form-test',
        'data_type' => 'number',
        'value_source' => 'free',
        'input_type' => 'number',
        'is_filterable' => true,
    ]);
}

test('attribute slider step cannot have more decimals than the attribute shows', function (): void {
    $attribute = numberAttributeForFormatForm();

    Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
        ->fillForm([
            'number_decimals' => 1,
            'number_step' => '0.25',
        ])
        ->call('save')
        ->assertHasFormErrors(['number_step']);

    Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
        ->fillForm([
            'number_decimals' => 2,
            'number_step' => '0.25',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $attribute->refresh()->number_step)->toBe(0.25);
});

test('attribute decimals cannot drop below the slider step of categories that inherit them', function (): void {
    $attribute = numberAttributeForFormatForm();

    $category = Category::query()->create([
        'name' => 'Садовые мини-тракторы',
        'slug' => 'garden-tractors-attribute-number-format-form-test',
        'parent_id' => Category::defaultParentKey(),
        'order' => 1,
        'is_active' => true,
    ]);

    // Своих знаков у категории нет, шаг ползунка свой — знаки она берёт у атрибута.
    $category->attributeDefs()->attach($attribute->id, ['number_step' => 0.25]);

    Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
        ->fillForm(['number_decimals' => 1])
        ->call('save')
        ->assertHasFormErrors(['number_decimals']);

    Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
        ->fillForm(['number_decimals' => 2])
        ->call('save')
        ->assertHasNoFormErrors();
});
