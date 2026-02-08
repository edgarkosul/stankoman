<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\QueuesContentImageDerivatives;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Schemas\PageForm;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    use QueuesContentImageDerivatives;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_webp_derivatives')
                ->label('Сгенерировать WebP')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->disabled(fn () => ! $this->hasAnyContentImages($this->pageContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->pageContentValues(), false);
                    $this->notifyContentImageDerivativesQueued($queued, false);
                }),
            Action::make('regenerate_webp_derivatives')
                ->label('Перегенерировать WebP (force)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->disabled(fn () => ! $this->hasAnyContentImages($this->pageContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->pageContentValues(), true);
                    $this->notifyContentImageDerivativesQueued($queued, true);
                }),
            ...PageForm::headerActions(),
            DeleteAction::make(),
        ];
    }

    private function pageContentValues(): array
    {
        $state = $this->form->getState();

        return [
            $state['content'] ?? $this->record->content,
        ];
    }
}
