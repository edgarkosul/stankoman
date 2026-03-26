<?php

use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Products\ProductResource;
use App\Models\ImportRun;
use App\Models\ImportRunEvent;
use App\Models\Product;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;

test('import run events relation manager uses russian labels and product edit link in new tab', function (): void {
    $this->actingAs(User::factory()->create());

    $run = ImportRun::query()->create([
        'type' => 'catalog_import_yml',
        'status' => 'done',
    ]);

    $product = Product::query()->create([
        'name' => 'Товар для ссылки из лога импорта',
        'slug' => 'import-log-product-link-test',
        'price_amount' => 1000,
    ]);

    $event = ImportRunEvent::query()->create([
        'run_id' => $run->id,
        'stage' => 'processing',
        'result' => 'updated',
        'external_id' => 'ext-1',
        'product_id' => $product->id,
        'source_ref' => 'source-1',
        'message' => 'Обновлен товар',
    ]);

    $expectedProductUrl = ProductResource::getUrl('edit', ['record' => $product->slug]);

    Livewire::test(EventsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewImportRun::class,
    ])
        ->assertCanSeeTableRecords([$event])
        ->assertTableColumnExists(
            'external_id',
            fn (TextColumn $column): bool => $column->getLabel() === 'Внешний ID',
            $event
        )
        ->assertTableColumnExists(
            'product_id',
            fn (TextColumn $column): bool => $column->getLabel() === 'ID товара'
                && $column->getUrl() === $expectedProductUrl
                && $column->shouldOpenUrlInNewTab(),
            $event
        )
        ->assertTableColumnExists(
            'source_ref',
            fn (TextColumn $column): bool => $column->getLabel() === 'Источник',
            $event
        );
});

test('import run events relation manager does not generate a broken product link for missing products', function (): void {
    $this->actingAs(User::factory()->create());

    $run = ImportRun::query()->create([
        'type' => 'catalog_import_yml',
        'status' => 'done',
    ]);

    $event = ImportRunEvent::query()->create([
        'run_id' => $run->id,
        'stage' => 'processing',
        'result' => 'updated',
        'external_id' => 'ext-missing',
        'product_id' => 999999,
        'source_ref' => 'source-missing',
        'message' => 'Товар уже удален',
    ]);

    Livewire::test(EventsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewImportRun::class,
    ])
        ->assertCanSeeTableRecords([$event])
        ->assertTableColumnExists(
            'product_id',
            fn (TextColumn $column): bool => $column->getLabel() === 'ID товара'
                && $column->getUrl() === null,
            $event
        );
});
