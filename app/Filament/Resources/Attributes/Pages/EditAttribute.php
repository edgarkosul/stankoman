<?php

namespace App\Filament\Resources\Attributes\Pages;

use App\Filament\Resources\Attributes\AttributeResource;
use App\Models\Attribute;
use App\Support\FilterSchemaCache;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditAttribute extends EditRecord
{
    protected static string $resource = AttributeResource::class;

    protected ?int $deletedAttributeId = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (): void {
                    $this->deletedAttributeId = (int) ($this->record?->getKey() ?? 0);
                })
                ->after(function (): void {
                    if (($this->deletedAttributeId ?? 0) > 0) {
                        FilterSchemaCache::forgetByAttribute($this->deletedAttributeId);
                    }

                    $this->deletedAttributeId = null;
                }),
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
        FilterSchemaCache::forgetByAttribute((int) $attribute->getKey());

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
