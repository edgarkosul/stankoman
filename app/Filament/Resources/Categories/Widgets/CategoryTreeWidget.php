<?php

namespace App\Filament\Resources\Categories\Widgets;

use App\Models\Category;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;

class CategoryTreeWidget extends Widget
{
    protected  string $view = 'filament.resources.categories.widgets.category-tree-widget';

    protected ?string $heading = 'Дерево категорий';

    public ?int $selectedId = null;

    protected $listeners = [
        'categories:refreshTree' => '$refresh',
    ];

    public function select(int $id): void
    {
        $this->selectedId = $id;

        // Сообщаем странице списка, чтобы она применила фильтр к таблице
        $this->dispatch('categories:setSelectedCategory', id: $id);
    }

    /** Корневые узлы (parent_id = -1) */
    public function roots()
    {
        return Category::query()
            ->where('parent_id', -1)
            ->orderBy('order')
            ->get();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $expandableIds = \App\Models\Category::query()
            ->whereHas('children')
            ->pluck('id')
            ->all();

        return view($this->view, [
            'roots' => $this->roots(),
            'selectedId' => $this->selectedId,
            'expandableIds' => $expandableIds,
        ]);
    }
}
