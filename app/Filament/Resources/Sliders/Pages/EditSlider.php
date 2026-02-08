<?php

namespace App\Filament\Resources\Sliders\Pages;

use App\Filament\Concerns\QueuesContentImageDerivatives;
use App\Filament\Resources\Sliders\SliderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSlider extends EditRecord
{
    use QueuesContentImageDerivatives;

    protected static string $resource = SliderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_webp_derivatives')
                ->label('Сгенерировать WebP')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->disabled(fn () => ! $this->hasAnyContentImages($this->sliderContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->sliderContentValues(), false);
                    $this->notifyContentImageDerivativesQueued($queued, false);
                }),
            Action::make('regenerate_webp_derivatives')
                ->label('Перегенерировать WebP (force)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->disabled(fn () => ! $this->hasAnyContentImages($this->sliderContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->sliderContentValues(), true);
                    $this->notifyContentImageDerivativesQueued($queued, true);
                }),
            DeleteAction::make(),
        ];
    }

    private function sliderContentValues(): array
    {
        $state = $this->form->getState();

        return [
            $state['slides'] ?? $this->record->slides,
        ];
    }
}
