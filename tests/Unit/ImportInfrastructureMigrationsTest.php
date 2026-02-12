<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('applies import infrastructure migrations', function () {
    $nameMigrationPath = collect(glob(database_path('migrations/*_add_name_normalized_to_products_table.php')))
        ->first();
    $importMigrationPath = collect(glob(database_path('migrations/*_create_import_runs_and_issues_tables.php')))
        ->first();

    expect($nameMigrationPath)->not->toBeNull();
    expect($importMigrationPath)->not->toBeNull();

    Schema::dropIfExists('import_issues');
    Schema::dropIfExists('import_runs');
    Schema::dropIfExists('products');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $nameMigration = require $nameMigrationPath;
    $nameMigration->up();

    $importMigration = require $importMigrationPath;
    $importMigration->up();

    expect(Schema::hasColumn('products', 'name_normalized'))->toBeTrue();
    expect(Schema::hasTable('import_runs'))->toBeTrue();
    expect(Schema::hasTable('import_issues'))->toBeTrue();
});
