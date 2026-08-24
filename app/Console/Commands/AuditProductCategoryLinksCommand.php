<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Pivots\ProductCategory;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AuditProductCategoryLinksCommand extends Command
{
    protected $signature = 'categories:audit-product-links';

    protected $description = 'Проверить прямые привязки товаров к нелистовым категориям';

    public function handle(): int
    {
        $query = $this->violationsQuery();
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Привязок товаров к нелистовым категориям не найдено.');

            return self::SUCCESS;
        }

        $rows = $query
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'categories.id as category_id',
                'categories.name as category_name',
                'product_categories.is_primary',
            ])
            ->orderBy('products.id')
            ->orderBy('categories.id')
            ->limit(100)
            ->get();

        $this->table(
            ['ID товара', 'Товар', 'ID категории', 'Нелистовая категория', 'Основная'],
            $rows
                ->map(static fn (object $row): array => [
                    $row->product_id,
                    $row->product_name,
                    $row->category_id,
                    $row->category_name,
                    $row->is_primary ? 'да' : 'нет',
                ])
                ->all(),
        );

        $this->error('Найдено некорректных привязок: '.$count.'.');

        if ($count > $rows->count()) {
            $this->warn('Показаны первые '.$rows->count().' записей.');
        }

        return self::FAILURE;
    }

    private function violationsQuery(): Builder
    {
        $pivotTable = (new ProductCategory)->getTable();
        $productsTable = (new Product)->getTable();
        $categoriesTable = (new Category)->getTable();

        return DB::table($pivotTable.' as product_categories')
            ->join($productsTable.' as products', 'products.id', '=', 'product_categories.product_id')
            ->join($categoriesTable.' as categories', 'categories.id', '=', 'product_categories.category_id')
            ->whereExists(function (Builder $query) use ($categoriesTable): void {
                $query
                    ->selectRaw('1')
                    ->from($categoriesTable.' as child_categories')
                    ->whereColumn('child_categories.parent_id', 'categories.id');
            });
    }
}
