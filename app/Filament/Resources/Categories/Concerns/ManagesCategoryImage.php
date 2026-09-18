<?php

namespace App\Filament\Resources\Categories\Concerns;

use App\Models\Category;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Картинка категории в форме: загрузка своего файла и очистка. Общая для создания
 * и редактирования — загрузке, в отличие от выбора из товаров, запись не нужна.
 */
trait ManagesCategoryImage
{
    public function openCategoryImageUpload(): void
    {
        $this->mountAction('uploadCategoryImage');
    }

    public function clearCategoryImage(): void
    {
        $this->syncCategoryImageState(null);
    }

    protected function categoryImageUploadAction(): Action
    {
        return Action::make('uploadCategoryImage')
            ->label('Загрузить изображение категории')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->extraAttributes(['class' => 'hidden'])
            ->modalHeading('Загрузка изображения категории')
            ->modalSubmitActionLabel('Загрузить')
            ->schema([
                FileUpload::make('image')
                    ->label('Файл')
                    ->disk('public')
                    ->directory(Category::IMAGE_UPLOAD_DIRECTORY)
                    ->image()
                    ->imageEditor()
                    ->imageEditorAspectRatios([
                        '16:9',
                        '4:3',
                        '1:1',
                    ])
                    ->maxSize(4096)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $path = Category::normalizeImagePath($this->resolveStoredUploadPath($data['image'] ?? null));

                if ($path === null) {
                    return;
                }

                $this->syncCategoryImageState($path);

                Notification::make()
                    ->success()
                    ->title('Изображение категории обновлено')
                    ->send();
            });
    }

    private function syncCategoryImageState(?string $path): void
    {
        $this->discardUnsavedCategoryImageUpload($path);

        $this->data['img'] = $path;
        $this->form->fill($this->data);
    }

    /**
     * Загруженный, но ещё не сохранённый файл при замене больше никому не нужен —
     * иначе каждая лишняя загрузка оседает в pics/categories навсегда. Сохранённый
     * не трогаем: его удалит модель, когда замену сохранят.
     */
    private function discardUnsavedCategoryImageUpload(?string $nextPath): void
    {
        $currentPath = Category::normalizeImagePath($this->data['img'] ?? null);

        if (! Category::ownsImageFile($currentPath)
            || $currentPath === Category::normalizeImagePath($nextPath)
            || $currentPath === Category::normalizeImagePath($this->record?->img)) {
            return;
        }

        Storage::disk('public')->delete($currentPath);
    }

    private function resolveStoredUploadPath(mixed $state): ?string
    {
        if (is_string($state) && $state !== '') {
            return $state;
        }

        if (is_array($state)) {
            $first = reset($state);

            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return null;
    }
}
