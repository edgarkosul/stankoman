<?php

use App\Models\Product;
use App\Support\Products\ProductSearchSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Schema::dropIfExists('products');

    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('name_normalized')->nullable();
        $table->string('slug')->unique();
        $table->string('sku')->nullable();
        $table->string('brand')->nullable();
        $table->unsignedInteger('price_amount')->default(0);
        $table->unsignedInteger('discount_price')->nullable();
        $table->char('currency', 3)->default('RUB');
        $table->boolean('in_stock')->default(true);
        $table->unsignedInteger('qty')->nullable();
        $table->unsignedInteger('popularity')->default(0);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_in_yml_feed')->default(true);
        $table->boolean('with_dns')->default(true);
        $table->timestamps();
    });
});

it('syncs searchable and unsearchable products by ids', function (): void {
    $engine = fakeScoutEngine();
    bindScoutEngine($engine);

    $activeProduct = Product::query()->create([
        'name' => 'Active Product',
        'price_amount' => 1000,
        'is_active' => true,
    ]);
    $inactiveProduct = Product::query()->create([
        'name' => 'Inactive Product',
        'price_amount' => 1000,
        'is_active' => false,
    ]);

    $engine->updatedIds = [];
    $engine->deletedIds = [];
    $engine->flushes = [];

    $result = app(ProductSearchSync::class)->syncIds([$inactiveProduct->id, $activeProduct->id]);

    expect($result)->toBe([
        'synced' => 1,
        'removed' => 1,
    ]);
    expect($engine->updatedIds)->toBe([$activeProduct->id]);
    expect($engine->deletedIds)->toBe([$inactiveProduct->id]);
});

it('rebuilds the product index synchronously', function (): void {
    $engine = fakeScoutEngine();
    bindScoutEngine($engine);

    $activeProduct = Product::query()->create([
        'name' => 'Indexed Product',
        'price_amount' => 1500,
        'is_active' => true,
    ]);
    Product::query()->create([
        'name' => 'Hidden Product',
        'price_amount' => 1700,
        'is_active' => false,
    ]);

    $engine->updatedIds = [];
    $engine->deletedIds = [];
    $engine->flushes = [];

    $result = app(ProductSearchSync::class)->rebuildIndex(1);

    expect($result)->toBe([
        'indexed' => 1,
    ]);
    expect($engine->flushes)->toHaveCount(1)
        ->and($engine->updatedIds)->toBe([$activeProduct->id])
        ->and($engine->deletedIds)->toBe([]);
});

function bindScoutEngine(Engine $engine): void
{
    app()->instance(EngineManager::class, new class($engine)
    {
        public function __construct(
            private readonly Engine $engine,
        ) {}

        public function engine(): Engine
        {
            return $this->engine;
        }
    });
}

function fakeScoutEngine(): Engine
{
    return new class extends Engine
    {
        /**
         * @var array<int, int>
         */
        public array $updatedIds = [];

        /**
         * @var array<int, int>
         */
        public array $deletedIds = [];

        /**
         * @var array<int, string>
         */
        public array $flushes = [];

        public function update($models): void
        {
            $this->updatedIds = array_merge($this->updatedIds, $models->pluck('id')->all());
        }

        public function delete($models): void
        {
            $this->deletedIds = array_merge($this->deletedIds, $models->pluck('id')->all());
        }

        public function search(Builder $builder): array
        {
            return [];
        }

        public function paginate(Builder $builder, $perPage, $page): array
        {
            return [];
        }

        public function mapIds($results): Collection
        {
            return collect();
        }

        public function map(Builder $builder, $results, $model): Illuminate\Database\Eloquent\Collection
        {
            return $model->newCollection();
        }

        public function lazyMap(Builder $builder, $results, $model): LazyCollection
        {
            return LazyCollection::make([]);
        }

        public function getTotalCount($results): int
        {
            return 0;
        }

        public function flush($model): void
        {
            $this->flushes[] = $model::class;
        }

        public function createIndex($name, array $options = []): array
        {
            return [];
        }

        public function deleteIndex($name): array
        {
            return [];
        }
    };
}
