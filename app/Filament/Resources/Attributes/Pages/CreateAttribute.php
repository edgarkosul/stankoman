<?php

namespace App\Filament\Resources\Attributes\Pages;

use App\Filament\Resources\Attributes\AttributeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAttribute extends CreateRecord
{
    protected static string $resource = AttributeResource::class;

    public  function mutateFormDataBeforeCreate(array $data): array
    {
        return AttributeResource::applyUiMap($data);
    }
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['filter_ui'] = $data['filter_ui'] ?? 'text';
        return $data;
    }
    protected function afterCreate(): void
    {
        /** @var Attribute $attribute */
        $attribute = $this->record;

        $state = $this->form->getRawState();
        $unitIds = $state['units_pivot'] ?? [];

        $attribute->syncUnitsFromIds($unitIds);
    }
}
