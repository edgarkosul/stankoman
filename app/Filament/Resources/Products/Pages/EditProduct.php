<?php

namespace App\Filament\Resources\Products\Pages;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Concerns\QueuesContentImageDerivatives;

class EditProduct extends EditRecord
{
    use QueuesContentImageDerivatives;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_webp_derivatives')
                ->label('Сгенерировать WebP')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->disabled(fn () => ! $this->hasAnyContentImages($this->productContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->productContentValues(), false);
                    $this->notifyContentImageDerivativesQueued($queued, false);
                }),
            Action::make('regenerate_webp_derivatives')
                ->label('Перегенерировать WebP (force)')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->disabled(fn () => ! $this->hasAnyContentImages($this->productContentValues()))
                ->action(function () {
                    $queued = $this->queueContentImageDerivatives($this->productContentValues(), true);
                    $this->notifyContentImageDerivativesQueued($queued, true);
                }),
            Action::make('open_public')
                ->label('')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn($record) => route('product.show', $record))
                ->openUrlInNewTab(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    private function productContentValues(): array
    {
        $state = $this->form->getState();

        return [
            $state['image'] ?? $this->record->image,
            $state['thumb'] ?? $this->record->thumb,
            $state['gallery'] ?? $this->record->gallery,
            $state['description'] ?? $this->record->description,
            $state['extra_description'] ?? $this->record->extra_description,
        ];
    }

    protected function beforeSave(): void
    {
        // Разрешаем сохранять «черновики», но блокируем публикацию.
        $isPublishing = (bool) data_get($this->data, 'is_active', $this->record->is_active);
        if (! $isPublishing) {
            return;
        }

        // Контекст: по умолчанию берём primary-категорию; можно заменить на null (= учесть любые категории товара).
        $categoryId = $this->record->primaryCategory()?->id;

        $missing = $this->record->missingRequiredAttributes($categoryId);
        if ($missing->isNotEmpty()) {
            $list = $missing->pluck('name')->implode(', ');

            Notification::make()
                ->danger()
                ->title('Нельзя сохранить — не заполнены обязательные атрибуты')
                ->body('Укажите значения: ' . $list)
                ->persistent()
                ->send();

            // Бросаем валидацию, чтобы прервать сохранение.
            throw ValidationException::withMessages([
                'is_active' => 'Заполните обязательные атрибуты: ' . $list,
            ]);
        }
    }
}
