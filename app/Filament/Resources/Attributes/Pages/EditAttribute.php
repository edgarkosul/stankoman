<?php

namespace App\Filament\Resources\Attributes\Pages;

use App\Filament\Resources\Attributes\AttributeResource;
use App\Models\Attribute;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditAttribute extends EditRecord
{
    protected static string $resource = AttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function mutateFormDataBeforeSave(array $data): array
    {
        return AttributeResource::normalizeTypePair($data);
    }

    protected function afterSave(): void
    {
        /** @var Attribute $attribute */
        $attribute = $this->record;

        $state = $this->form->getRawState();
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
