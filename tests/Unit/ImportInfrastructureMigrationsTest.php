<?php

use App\Models\Product;
use App\Support\NameNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('applies import infrastructure migrations', function () {
    $nameMigrationPath = collect(glob(database_path('migrations/*_add_name_normalized_to_products_table.php')))
        ->first();
    $nameBackfillMigrationPath = collect(glob(database_path('migrations/*_backfill_name_normalized_on_products_table.php')))
        ->first();
    $importMigrationPath = collect(glob(database_path('migrations/*_create_import_runs_and_issues_tables.php')))
        ->first();
    $eventLogMigrationPath = collect(glob(database_path('migrations/*_create_import_run_events_table.php')))
        ->first();
    $supplierImportSourcesMigrationPath = collect(glob(database_path('migrations/*_create_supplier_import_sources_table.php')))
        ->first();
    $importRunsSupplierContextMigrationPath = collect(glob(database_path('migrations/*_add_supplier_context_to_import_runs_table.php')))
        ->first();

    expect($nameMigrationPath)->not->toBeNull();
    expect($nameBackfillMigrationPath)->not->toBeNull();
    expect($importMigrationPath)->not->toBeNull();
    expect($eventLogMigrationPath)->not->toBeNull();
    expect($supplierImportSourcesMigrationPath)->not->toBeNull();
    expect($importRunsSupplierContextMigrationPath)->not->toBeNull();

    Schema::dropIfExists('import_run_events');
    Schema::dropIfExists('import_issues');
    Schema::dropIfExists('import_runs');
    Schema::dropIfExists('supplier_import_sources');
    Schema::dropIfExists('suppliers');
    Schema::dropIfExists('products');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('suppliers', function (Blueprint $table): void {
        $table->id();
        $table->string('name', 160)->unique();
        $table->string('slug', 180)->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->timestamps();
    });

    $sourceName = "  Шлифмашина\t— 125мм  ";

    DB::table('products')->insert([
        'name' => $sourceName,
        'slug' => 'legacy-product',
    ]);

    $nameMigration = require $nameMigrationPath;
    $nameMigration->up();

    $nameBackfillMigration = require $nameBackfillMigrationPath;
    $nameBackfillMigration->up();

    $importMigration = require $importMigrationPath;
    $importMigration->up();

    $eventLogMigration = require $eventLogMigrationPath;
    $eventLogMigration->up();

    $supplierImportSourcesMigration = require $supplierImportSourcesMigrationPath;
    $supplierImportSourcesMigration->up();

    $importRunsSupplierContextMigration = require $importRunsSupplierContextMigrationPath;
    $importRunsSupplierContextMigration->up();

    expect(Schema::hasColumn('products', 'name_normalized'))->toBeTrue();
    expect(Schema::hasTable('import_runs'))->toBeTrue();
    expect(Schema::hasTable('import_issues'))->toBeTrue();
    expect(Schema::hasTable('import_run_events'))->toBeTrue();
    expect(Schema::hasTable('supplier_import_sources'))->toBeTrue();
    expect(Schema::hasColumn('import_runs', 'supplier_id'))->toBeTrue();
    expect(Schema::hasColumn('import_runs', 'supplier_import_source_id'))->toBeTrue();

    expect(
        DB::table('products')
            ->where('slug', 'legacy-product')
            ->value('name_normalized')
    )->toBe(NameNormalizer::normalize($sourceName));

    $created = Product::query()->create([
        'name' => '  Новый    ТОВАР  ',
    ]);

    expect($created->name_normalized)->toBe(NameNormalizer::normalize('  Новый    ТОВАР  '));
    expect((string) $created->slug)->not->toBe('');
});
