<?php

namespace App\Filament\Resources\Attributes\Pages;

use Livewire\Attributes\On;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Attributes\AttributeResource;
use App\Models\Attribute;

class EditAttribute extends EditRecord
{
    protected static string $resource = AttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Превращаем текущее (data_type, input_type) -> виртуальный filter_ui
        $data['filter_ui'] =
            ($data['input_type'] === 'select')       ? 'select'      : (($data['input_type'] === 'multiselect') ? 'multiselect' : (($data['data_type']  === 'number')      ? 'number'      : (($data['data_type']  === 'range')       ? 'range'       : (($data['data_type']  === 'boolean')     ? 'boolean'     : 'text'))));

        return $data;
    }

    public function mutateFormDataBeforeSave(array $data): array
    {
        return AttributeResource::applyUiMap($data);
    }
    protected function afterSave(): void
    {
        /** @var Attribute $attribute */
        $attribute = $this->record;

        $state   = $this->form->getRawState();
        $unitIds = $state['units_pivot'] ?? [];

        $attribute->syncUnitsFromIds($unitIds);

        // твой исходный евент остаётся
        $this->dispatch('attribute-updated', id: $attribute->getKey());
    }

    #[On('attribute-updated')]
    public function refreshSelf(): void
    {
        $this->record->refresh();
        $this->dispatch('$refresh');
    }
}
