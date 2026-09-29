<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Concerns\ManagesCategoryImage;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    use ManagesCategoryImage;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->categoryImageUploadAction(),
        ];
    }
}
