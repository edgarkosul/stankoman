<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Support\Categories\EmptyCategoryPruner;
use Illuminate\Console\Command;

class PruneEmptyCategoriesCommand extends Command
{
    protected $signature = 'categories:prune-empty
        {--write : Actually delete empty categories recursively (otherwise dry-run)}';

    protected $description = 'Recursively remove all empty categories; dry-run by default';

    public function handle(EmptyCategoryPruner $pruner): int
    {
        $write = (bool) $this->option('write');
        $plan = $pruner->plan();

        $this->line('Режим: '.($write ? 'write' : 'dry-run'));

        if ($plan['categories']->isEmpty()) {
            $this->info('Пустые категории не найдены.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Type', 'Depth', 'Name', 'Slug', 'Parent ID'],
            $plan['categories']
                ->map(
                    static fn (Category $category): array => [
                        $category->id,
                        $category->getAttribute('node_type'),
                        $category->getAttribute('depth'),
                        $category->name,
                        $category->slug,
                        $category->parent_id,
                    ]
                )
                ->all()
        );

        $this->newLine();
        $this->line('Категорий к удалению: '.$plan['categories']->count());
        $this->line('Веток: '.$plan['branch_count']);
        $this->line('Листьев: '.$plan['leaf_count']);

        if (! $write) {
            $this->warn('Dry-run: данные не изменены.');

            return self::SUCCESS;
        }

        $deleted = $pruner->prune($plan['categories']);

        $this->info('Удалено категорий: '.$deleted);

        return self::SUCCESS;
    }
}
