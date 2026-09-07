<?php

use App\Support\Products\ProductSearchSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('reports stale discounts of a run without touching data', function (): void {
    rebuildRepairStaleImportDiscountSchemas();

    try {
        $ids = seedRepairStaleImportDiscountFixtures();

        $searchSync = Mockery::mock(ProductSearchSync::class);
        $searchSync->shouldNotReceive('syncIds');
        app()->instance(ProductSearchSync::class, $searchSync);

        $this->artisan('catalog:repair-stale-import-discounts', ['run' => 7])
            ->expectsOutputToContain('Режим команды: dry-run')
            ->expectsOutputToContain('к исправлению: 1')
            ->assertSuccessful();

        expect(DB::table('products')->where('id', $ids['stale'])->value('discount_price'))->toBe(12105);
    } finally {
        dropRepairStaleImportDiscountSchemas();
    }
});

it('restores the discount percentage the product had before the run', function (): void {
    rebuildRepairStaleImportDiscountSchemas();

    try {
        $ids = seedRepairStaleImportDiscountFixtures();

        $searchSync = Mockery::mock(ProductSearchSync::class);
        $searchSync->shouldReceive('syncIds')
            ->once()
            ->with([$ids['stale']])
            ->andReturn(['synced' => 1, 'removed' => 0]);
        app()->instance(ProductSearchSync::class, $searchSync);

        $this->artisan('catalog:repair-stale-import-discounts', ['run' => 7, '--write' => true])
            ->expectsOutputToContain('обновлено: 1')
            ->assertSuccessful();

        // 12 105 от 13 450 — это −10%; после подорожания до 14 550 те же −10% дают 13 095.
        expect(DB::table('products')->where('id', $ids['stale'])->value('discount_price'))->toBe(13095)
            // Процентную скидку модель пересчитала сама ещё во время импорта.
            ->and(DB::table('products')->where('id', $ids['percent'])->value('discount_price'))->toBe(9000)
            // Цену этого товара после прогона правили руками — исходный процент неизвестен.
            ->and(DB::table('products')->where('id', $ids['moved'])->value('discount_price'))->toBe(12105)
            ->and(DB::table('products')->where('id', $ids['no_discount'])->value('discount_price'))->toBeNull();
    } finally {
        dropRepairStaleImportDiscountSchemas();
    }
});

it('lists runs that actually changed prices when no run is given', function (): void {
    rebuildRepairStaleImportDiscountSchemas();

    try {
        seedRepairStaleImportDiscountFixtures();

        $this->artisan('catalog:repair-stale-import-discounts')
            ->expectsOutputToContain('Прогоны, в которых импорт менял цены')
            ->expectsOutputToContain('yandex_market_feed_products')
            ->assertSuccessful();
    } finally {
        dropRepairStaleImportDiscountSchemas();
    }
});

it('explains that a dry-run has nothing to repair', function (): void {
    rebuildRepairStaleImportDiscountSchemas();

    try {
        DB::table('import_runs')->insert([
            'id' => 9,
            'type' => 'yandex_market_feed_products',
            'status' => 'completed',
            'totals' => json_encode(['_meta' => ['mode' => 'dry-run']]),
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('catalog:repair-stale-import-discounts', ['run' => 9])
            ->expectsOutputToContain('холостым (в базу не писал)')
            ->expectsOutputToContain('нет событий обновления товаров')
            ->assertSuccessful();
    } finally {
        dropRepairStaleImportDiscountSchemas();
    }
});

/**
 * @return array<string, int>
 */
function seedRepairStaleImportDiscountFixtures(): array
{
    DB::table('import_runs')->insert([
        'id' => 7,
        'type' => 'yandex_market_feed_products',
        'status' => 'completed',
        'started_at' => now(),
        'finished_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ids = [];

    $products = [
        // скидка абсолютным числом — отстала от новой цены
        'stale' => ['price' => 14550, 'discount' => 12105, 'percent' => null],
        // скидка процентом — пересчиталась сама
        'percent' => ['price' => 10000, 'discount' => 9000, 'percent' => 10],
        // цену меняли уже после прогона
        'moved' => ['price' => 15000, 'discount' => 12105, 'percent' => null],
        // скидки нет
        'no_discount' => ['price' => 14550, 'discount' => null, 'percent' => null],
    ];

    foreach ($products as $key => $data) {
        $ids[$key] = (int) DB::table('products')->insertGetId([
            'name' => 'Товар '.$key,
            'slug' => 'tovar-'.$key,
            'sku' => mb_strtoupper($key),
            'price_amount' => $data['price'],
            'discount_price' => $data['discount'],
            'discount_percent' => $data['percent'],
            'currency' => 'RUB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach (['stale', 'percent', 'moved', 'no_discount'] as $key) {
        DB::table('import_run_events')->insert([
            'run_id' => 7,
            'supplier' => 'yandex_market_feed',
            'stage' => 'processing',
            'result' => 'updated',
            'product_id' => $ids[$key],
            'context' => json_encode([
                'changes' => [
                    'price_amount' => ['before' => 13450, 'after' => 14550],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Событие другого прогона трогать нельзя.
    DB::table('import_run_events')->insert([
        'run_id' => 8,
        'supplier' => 'yandex_market_feed',
        'stage' => 'processing',
        'result' => 'updated',
        'product_id' => $ids['no_discount'],
        'context' => json_encode([
            'changes' => [
                'price_amount' => ['before' => 100, 'after' => 200],
            ],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $ids;
}

function rebuildRepairStaleImportDiscountSchemas(): void
{
    dropRepairStaleImportDiscountSchemas();

    Schema::create('import_runs', function (Blueprint $table): void {
        $table->id();
        $table->string('type');
        $table->string('status');
        $table->json('columns')->nullable();
        $table->json('totals')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();
    });

    Schema::create('import_run_events', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('run_id');
        $table->string('supplier', 120)->nullable();
        $table->string('stage', 32);
        $table->string('result', 32);
        $table->string('external_id')->nullable();
        $table->unsignedBigInteger('product_id')->nullable();
        $table->string('code', 64)->nullable();
        $table->text('message')->nullable();
        $table->json('context')->nullable();
        $table->timestamps();
    });

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('slug')->unique();
        $table->string('sku')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->decimal('discount_percent', 5, 2)->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

function dropRepairStaleImportDiscountSchemas(): void
{
    Schema::dropIfExists('import_run_events');
    Schema::dropIfExists('import_runs');
    Schema::dropIfExists('products');
    DB::disconnect();
}
