<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class YoutubeVideoBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'youtube-video';
    }

    public static function getLabel(): string
    {
        return 'Видео YouTube';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Видео YouTube')
            ->modalDescription('Вставьте ссылку на видео YouTube — идентификатор определится автоматически.')
            ->schema([
                TextInput::make('url')
                    ->label('Ссылка на видео YouTube')
                    ->helperText('Например: https://www.youtube.com/watch?v=M7lc1UVf-VE')
                    ->required()
                    ->maxLength(1024)
                    ->rule(static function () {
                        return static function (string $attribute, mixed $value, \Closure $fail): void {
                            if (self::parseVideoId(is_string($value) ? $value : null) === null) {
                                $fail('Не удалось распознать ссылку на видео YouTube.');
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
     * Извлекает ID видео YouTube из ссылки или принимает уже готовый ID.
     *
     * Поддерживаемые форматы:
     *  - https://www.youtube.com/watch?v=M7lc1UVf-VE
     *  - https://youtu.be/M7lc1UVf-VE
     *  - https://www.youtube.com/embed/M7lc1UVf-VE
     *  - https://www.youtube.com/shorts/M7lc1UVf-VE
     *  - M7lc1UVf-VE (голый ID для обратной совместимости)
     */
    public static function parseVideoId(?string $input): ?string
    {
        if (! is_string($input) || trim($input) === '') {
            return null;
        }

        $input = trim($input);

        // Голый ID (старый формат / ручной ввод).
        if (preg_match('#^[A-Za-z0-9_-]{11}$#', $input) === 1) {
            return $input;
        }

        // ?v=<id>
        if (($query = parse_url($input, PHP_URL_QUERY)) !== null && $query !== false) {
            parse_str($query, $params);

            if (isset($params['v']) && is_string($params['v']) && preg_match('#^[A-Za-z0-9_-]{11}$#', $params['v']) === 1) {
                return $params['v'];
            }
        }

        // youtu.be/<id>, /embed/<id>, /shorts/<id>, /v/<id>
        if (preg_match('#(?:youtu\.be/|/embed/|/shorts/|/v/)([A-Za-z0-9_-]{11})#', $input, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Возвращает ID видео из конфигурации блока с учётом старого поля video_id.
     *
     * @param  array<string, mixed>  $config
     */
    private static function resolveVideoId(array $config): ?string
    {
        if (isset($config['url'])) {
            return self::parseVideoId(is_string($config['url']) ? $config['url'] : null);
        }

        // Обратная совместимость со старыми блоками.
        return self::parseVideoId($config['video_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.youtube-video.preview', [
            'videoId' => self::resolveVideoId($config),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.youtube-video.index', [
            'videoId' => self::resolveVideoId($config),
            'width' => is_numeric($config['width'] ?? null) ? (int) $config['width'] : null,
            'alignment' => $config['alignment'] ?? 'center',
        ])->render();
    }
}
