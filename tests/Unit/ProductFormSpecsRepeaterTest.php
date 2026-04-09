<?php

use App\Enums\ProductWarranty;
use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\PdfLinkBlock;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('configures specs as a compact table repeater in product form', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));
    $specsField = $schema->getComponentByStatePath('specs', withHidden: true);

    expect($specsField)->toBeInstanceOf(Repeater::class);

    /** @var Repeater $specsField */
    $tableColumns = $specsField->getTableColumns();

    expect($specsField->isTable())->toBeTrue()
        ->and($specsField->isCompact())->toBeTrue()
        ->and($tableColumns)->toHaveCount(3)
        ->and($tableColumns[0]->getLabel())->toBe('Параметр')
        ->and($tableColumns[0]->isMarkedAsRequired())->toBeTrue()
        ->and($tableColumns[1]->getLabel())->toBe('Значение')
        ->and($tableColumns[1]->isMarkedAsRequired())->toBeTrue()
        ->and($tableColumns[2]->getLabel())->toBe('Источник');

    $sourceField = $specsField->getChildSchema()->getFlatFields(withHidden: true)['source'] ?? null;

    expect($sourceField)->toBeInstanceOf(Select::class);

    /** @var Select $sourceField */
    expect(array_keys($sourceField->getOptions()))->toBe([
        'manual',
        'jsonld',
        'inertia',
        'dom',
        'import',
        'legacy',
        'yml',
    ]);
});

it('normalizes specs state for persistence', function (): void {
    $normalized = ProductForm::normalizeSpecsState([
        ['name' => ' Объем бака ', 'value' => ' 60 л ', 'source' => 'jsonld'],
        ['name' => 'Объем бака', 'value' => '60 л', 'source' => 'dom'],
        ['name' => 'Вес', 'value' => '42 кг', 'source' => ''],
        ['name' => 'Напряжение', 'value' => 220, 'source' => true],
        ['name' => '', 'value' => 'x', 'source' => 'dom'],
        ['name' => 'Шум', 'value' => null, 'source' => 'dom'],
        'invalid',
    ]);

    expect($normalized)->toBe([
        ['name' => 'Объем бака', 'value' => '60 л', 'source' => 'jsonld'],
        ['name' => 'Вес', 'value' => '42 кг', 'source' => 'manual'],
        ['name' => 'Напряжение', 'value' => '220', 'source' => '1'],
    ]);

    expect(ProductForm::normalizeSpecsState([
        ['name' => '', 'value' => '', 'source' => null],
        ['name' => ' ', 'value' => ' ', 'source' => 'manual'],
    ]))->toBeNull();
});

it('configures warranty as enum select with nullable placeholder', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));
    $warrantyField = $schema->getComponentByStatePath('warranty', withHidden: true);

    expect($warrantyField)->toBeInstanceOf(Select::class);

    /** @var Select $warrantyField */
    expect($warrantyField->getOptions())->toBe(ProductWarranty::options())
        ->and($warrantyField->getPlaceholder())->toBe('Без гарантии');
});

it('uses meta_title as the editable seo title field', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));

    $metaTitleField = $schema->getComponentByStatePath('meta_title', withHidden: true);
    $legacyTitleField = $schema->getComponentByStatePath('title', withHidden: true);

    expect($metaTitleField)->toBeInstanceOf(TextInput::class)
        ->and($metaTitleField->getLabel())->toBe('META Title')
        ->and($legacyTitleField)->toBeNull();
});

it('configures pricing parameters with site price backed by price amount', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));

    $pricingFields = [
        'wholesale_price' => [TextInput::class, 'Цена опт'],
        'wholesale_currency' => [Select::class, 'Валюта'],
        'auto_update_exchange_rate' => [Toggle::class, 'Обновлять по курсу ЦБ'],
        'exchange_rate' => [TextInput::class, 'Курс валюты'],
        'wholesale_price_rub' => [TextInput::class, 'Опт, руб'],
        'markup_multiplier' => [TextInput::class, 'Наценка'],
        'price_amount' => [TextInput::class, 'Цена на сайт, руб'],
        'margin_amount_rub' => [TextInput::class, 'Маржа, руб'],
        'discount_percent' => [TextInput::class, 'Скидка в %'],
        'discount_price' => [TextInput::class, 'Цена со скидкой'],
    ];

    foreach ($pricingFields as $statePath => [$expectedClass, $label]) {
        $field = $schema->getComponentByStatePath($statePath, withHidden: true);

        expect($field)->toBeInstanceOf($expectedClass)
            ->and($field->getLabel())->toBe($label);
    }

    $pricingSections = array_filter(
        $schema->getFlatComponents(withActions: false, withHidden: true),
        fn (mixed $component): bool => $component instanceof Section && $component->getHeading() === 'Ценообразование',
    );

    /** @var Section $pricingSection */
    $pricingSection = array_values($pricingSections)[0] ?? null;

    expect($pricingSection)->toBeInstanceOf(Section::class)
        ->and($pricingSection->getColumns('default'))->toBe(2)
        ->and($pricingSection->getColumns('lg'))->toBe(3);
});

it('formats exchange rate field state to two decimals on hydration', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));
    $exchangeRateField = $schema->getComponentByStatePath('exchange_rate', withHidden: true);

    expect($exchangeRateField)->toBeInstanceOf(TextInput::class);

    /** @var TextInput $exchangeRateField */
    $exchangeRateField->state('78.3043');
    $exchangeRateField->callAfterStateHydrated();

    expect($exchangeRateField->getState())->toBe(78.3);
});

it('formats discount percent field state from site and discount prices on hydration', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));
    $priceField = $schema->getComponentByStatePath('price_amount', withHidden: true);
    $discountPriceField = $schema->getComponentByStatePath('discount_price', withHidden: true);
    $discountPercentField = $schema->getComponentByStatePath('discount_percent', withHidden: true);

    expect($discountPercentField)->toBeInstanceOf(TextInput::class);

    $priceField->state(1000);
    $discountPriceField->state(850);

    /** @var TextInput $discountPercentField */
    $discountPercentField->callAfterStateHydrated();

    expect($discountPercentField->getState())->toBe(15.0);
});

it('configures instructions and video as separate rich editors', function (): void {
    $page = new CreateProduct;
    $schema = $page->form(Schema::make($page));

    $instructionsField = $schema->getComponentByStatePath('instructions', withHidden: true);
    $videoField = $schema->getComponentByStatePath('video', withHidden: true);

    expect($instructionsField)->toBeInstanceOf(RichEditor::class)
        ->and($videoField)->toBeInstanceOf(RichEditor::class);

    /** @var RichEditor $instructionsField */
    /** @var RichEditor $videoField */
    expect($instructionsField->isLabelHidden())->toBeTrue()
        ->and($videoField->isLabelHidden())->toBeTrue()
        ->and($instructionsField->getCustomBlocks())->toContain(PdfLinkBlock::class)
        ->and($videoField->getCustomBlocks())->toContain(PdfLinkBlock::class);
});
