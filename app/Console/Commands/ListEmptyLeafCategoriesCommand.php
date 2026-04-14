<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;

class ListEmptyLeafCategoriesCommand extends Command
{
    protected $signature = 'categories:list-empty-leaves';

    protected $description = 'Вывести все концевые категории без товаров';

    public function handle(): int
    {
        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'parent_id'])
            ->leaf()
            ->doesntHave('products')
            ->orderBy('parent_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        if ($categories->isEmpty()) {
            $this->info('Концевые категории без товаров не найдены.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Slug', 'Parent ID'],
            $categories
                ->map(
                    static fn (Category $category): array => [
                        $category->id,
                        $category->name,
                        $category->slug,
                        $category->parent_id,
                    ]
                )
                ->all()
        );

        $this->info('Найдено: '.$categories->count());

        return self::SUCCESS;
    }
}
