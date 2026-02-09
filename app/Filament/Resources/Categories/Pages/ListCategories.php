<?php

namespace App\Filament\Resources\Categories\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Widgets\CategoryTreeWidget;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    // public ?int $selectedCategoryId = null;

    // protected $listeners = [
    //     'categories:setSelectedCategory' => 'setSelectedCategory',
    // ];

    // public function setSelectedCategory(int $id): void
    // {
    //     $this->selectedCategoryId = $id;

    //     // Обновляем таблицу
    //     $this->dispatch('$refresh');
    // }

    protected function getHeaderWidgets(): array
    {
        return [
            // CategoryTreeWidget::class,
        ];
    }
    public  function getHeaderWidgetsColumns(): int|array
    {
        return 1; // одна колонка => виджет растянется на всю ширину
    }

    // (опционально) Сразу раскрыть/выбрать корень:
    // public function mount(): void
    // {
    //     parent::mount();

    //     if ($this->selectedCategoryId === null) {
    //         // Можно выбрать -1 (корень) или оставить null,
    //         // чтобы таблица показывала top-level категории
    //         $this->selectedCategoryId = -1;
    //     }
    // }
}
