<?php

namespace App\Filament\Resources\Categories\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Concerns\QueuesContentImageDerivatives;

class EditCategory extends EditRecord
{
    use QueuesContentImageDerivatives;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_webp_derivatives')
                ->label('Сгенерировать WebP')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->disabled(fn () => ! $this->hasAnyContentImages($this->categoryContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->categoryContentValues(), false);
                    $this->notifyContentImageDerivativesQueued($queued, false);
                }),
            Action::make('regenerate_webp_derivatives')
                ->label('Перегенерировать WebP (force)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->disabled(fn () => ! $this->hasAnyContentImages($this->categoryContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->categoryContentValues(), true);
                    $this->notifyContentImageDerivativesQueued($queued, true);
                }),
            Action::make('view_public')
                ->label('Открыть на сайте')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(
                    fn($record) => $record
                        ? route('catalog.leaf', ['path' => $record->slug_path])
                        : null
                )
                ->openUrlInNewTab()
                ->visible(fn($record) => filled($record?->slug)),
            DeleteAction::make(),
        ];
    }

    private function categoryContentValues(): array
    {
        $state = $this->form->getState();

        return [
            $state['img'] ?? $this->record?->img,
        ];
    }
}
