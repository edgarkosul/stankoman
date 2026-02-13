<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes legacy specs payloads to json structure and restores text on rollback', function () {
    $migrationPath = collect(glob(database_path('migrations/*_migrate_products_specs_to_json_column.php')))
        ->first();

    expect($migrationPath)->not->toBeNull();

    Schema::dropIfExists('products');
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->longText('specs')->nullable();
        $table->timestamps();
    });

    $productId = DB::table('products')->insertGetId([
        'name' => 'Legacy Product',
        'slug' => 'legacy-product',
        'specs' => "Мощность: 2200 Вт\nОбъем бака: 80 л",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require $migrationPath;
    $migration->up();

    $storedSpecs = DB::table('products')
        ->where('id', $productId)
        ->value('specs');

    $decodedSpecs = is_string($storedSpecs) ? json_decode($storedSpecs, true) : $storedSpecs;

    expect($decodedSpecs)->toBe([
        ['name' => 'Мощность', 'value' => '2200 Вт', 'source' => 'legacy'],
        ['name' => 'Объем бака', 'value' => '80 л', 'source' => 'legacy'],
    ]);

    DB::table('products')
        ->where('id', $productId)
        ->update([
            'specs' => json_encode([
                ['name' => 'Уровень шума', 'value' => '64 дБ', 'source' => 'jsonld'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

    $migration->down();

    $restoredSpecs = DB::table('products')
        ->where('id', $productId)
        ->value('specs');

    expect($restoredSpecs)->toBe('Уровень шума: 64 дБ');
});
