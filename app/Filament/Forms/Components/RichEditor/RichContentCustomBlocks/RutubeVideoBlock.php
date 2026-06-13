<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class RutubeVideoBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'rutube-video';
    }

    public static function getLabel(): string
    {
        return 'Видео Rutube';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Видео Rutube')
            ->modalDescription('Вставьте ссылку на видео Rutube — идентификатор определится автоматически.')
            ->schema([
                TextInput::make('url')
                    ->label('Ссылка на видео Rutube')
                    ->helperText('Например: https://rutube.ru/video/1b883a32340ac3c8f33b77695879a227/')
                    ->required()
                    ->maxLength(1024)
                    ->rule(static function () {
                        return static function (string $attribute, mixed $value, \Closure $fail): void {
                            if (self::parseVideoId(is_string($value) ? $value : null) === null) {
                                $fail('Не удалось распознать ссылку на видео Rutube.');
                            }
                        };
                    }),

                TextInput::make('width')
                    ->belowContent('Ширина видео в пикселях. Оставьте пустым для адаптивной ширины.')
                    ->label('Ширина, px')
                    ->numeric()
                    ->nullable()
                    ->minValue(1),

                Select::make('alignment')
                    ->label('Выравнивание')
                    ->options([
                        'left' => 'По левому краю',
                        'center' => 'По центру',
                    ])
                    ->default('center')
                    ->required(),
            ]);
    }

    /**
     * Извлекает ID видео Rutube из ссылки или принимает уже готовый ID.
     *
     * Поддерживаемые форматы:
     *  - https://rutube.ru/video/1b883a32340ac3c8f33b77695879a227/
     *  - https://rutube.ru/play/embed/1b883a32340ac3c8f33b77695879a227
     *  - 1b883a32340ac3c8f33b77695879a227 (голый ID для обратной совместимости)
     */
    public static function parseVideoId(?string $input): ?string
    {
        if (! is_string($input) || trim($input) === '') {
            return null;
        }

        $input = trim($input);

        // Голый ID (старый формат / ручной ввод).
        if (preg_match('#^[a-f0-9]{32}$#i', $input) === 1) {
            return strtolower($input);
        }

        // .../video/<id>, .../play/embed/<id>, .../video/private/<id>
        if (preg_match('#([a-f0-9]{32})#i', $input, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Возвращает ID видео из конфигурации блока с учётом старого поля rutube_id.
     *
     * @param  array<string, mixed>  $config
     */
    private static function resolveVideoId(array $config): ?string
    {
        if (isset($config['url'])) {
            return self::parseVideoId(is_string($config['url']) ? $config['url'] : null);
        }

        // Обратная совместимость со старыми блоками.
        return self::parseVideoId($config['rutube_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.rutube-video.preview', [
            'rutubeId' => self::resolveVideoId($config),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.rutube-video.index', [
            'rutubeId' => self::resolveVideoId($config),
            'width' => is_numeric($config['width'] ?? null) ? (int) $config['width'] : null,
            'alignment' => $config['alignment'] ?? 'center',
        ])->render();
    }
}
