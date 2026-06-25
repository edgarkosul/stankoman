<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

function massEditPdfStagingCategory(): Category
{
    return Category::query()->firstOrCreate(
        ['slug' => 'staging'],
        [
            'name' => 'Staging',
            'parent_id' => -1,
            'order' => 999,
            'is_active' => true,
        ],
    );
}

/**
 * @return array<string, mixed>|null
 */
function massEditPdfBlockConfig(string $instructions): ?array
{
    if (preg_match('/data-config="([^"]*)"/', $instructions, $matches) !== 1) {
        return null;
    }

    $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true);

    return is_array($decoded) ? $decoded : null;
}

it('appends the same PDF block to instructions of every selected product', function (): void {
    $this->actingAs(User::factory()->create());

    $staging = massEditPdfStagingCategory();

    $empty = Product::query()->create([
        'name' => 'Товар без инструкций',
        'slug' => 'pdf-bulk-empty',
        'price_amount' => 5_000,
    ]);
    $empty->categories()->attach($staging->id, ['is_primary' => true]);

    $withContent = Product::query()->create([
        'name' => 'Товар с инструкциями',
        'slug' => 'pdf-bulk-existing',
        'price_amount' => 6_000,
        'instructions' => '<p>Существующий текст</p>',
    ]);
    $withContent->categories()->attach($staging->id, ['is_primary' => true]);

    Livewire::test(ListProducts::class)
        ->callTableBulkAction('massEdit', [$empty, $withContent], [
            'mode' => 'instructions_pdf',
            'pdf_source_type' => 'direct_url',
            'pdf_url' => 'https://example.com/catalog.pdf',
            'pdf_link_text' => 'Каталог 2025',
            'pdf_target' => '_blank',
        ])
        ->assertHasNoActionErrors();

    $expectedConfig = [
        'source_type' => 'direct_url',
        'target' => '_blank',
        'link_text' => 'Каталог 2025',
        'file' => null,
        'url' => 'https://example.com/catalog.pdf',
    ];

    $emptyInstructions = (string) $empty->refresh()->instructions;
    $withContentInstructions = (string) $withContent->refresh()->instructions;

    expect($emptyInstructions)
        ->toContain('data-type="customBlock"')
        ->toContain('data-id="pdf-link"');
    expect(massEditPdfBlockConfig($emptyInstructions))->toBe($expectedConfig);

    // Существующий контент сохраняется, блок дописывается в конец.
    expect($withContentInstructions)
        ->toStartWith('<p>Существующий текст</p>')
        ->toContain('data-id="pdf-link"');
    expect(massEditPdfBlockConfig($withContentInstructions))->toBe($expectedConfig);
});

it('falls back to the file/url name when link text is empty', function (): void {
    $this->actingAs(User::factory()->create());

    $staging = massEditPdfStagingCategory();

    $product = Product::query()->create([
        'name' => 'Товар для PDF без текста',
        'slug' => 'pdf-bulk-fallback',
        'price_amount' => 7_000,
    ]);
    $product->categories()->attach($staging->id, ['is_primary' => true]);

    Livewire::test(ListProducts::class)
        ->callTableBulkAction('massEdit', [$product], [
            'mode' => 'instructions_pdf',
            'pdf_source_type' => 'direct_url',
            'pdf_url' => 'https://example.com/docs/manual.pdf',
            'pdf_link_text' => '',
            'pdf_target' => '_blank',
        ])
        ->assertHasNoActionErrors();

    $config = massEditPdfBlockConfig((string) $product->refresh()->instructions);

    expect($config)->not->toBeNull()
        ->and($config['link_text'])->toBe('manual.pdf');
});
