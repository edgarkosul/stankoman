<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class VkVideoBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'vk-video';
    }

    public static function getLabel(): string
    {
        return 'Видео VK';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Видео VK')
            ->modalDescription('Вставьте ссылку на видео VK — oid и id определятся автоматически.')
            ->schema([
                TextInput::make('url')
                    ->label('Ссылка на видео VK')
                    ->helperText('Например: https://vkvideo.ru/video-211232966_456241362')
                    ->required()
                    ->maxLength(1024)
                    ->rule(static function () {
                        return static function (string $attribute, mixed $value, \Closure $fail): void {
                            if (self::parseVideo(is_string($value) ? $value : null) === null) {
                                $fail('Не удалось распознать ссылку на видео VK. Проверьте, что это ссылка вида https://vkvideo.ru/video-XXXX_YYYY');
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
     * Извлекает oid, id и hash из ссылки на видео VK.
     *
     * Поддерживаемые форматы:
     *  - https://vkvideo.ru/video-211232966_456241362
     *  - https://vk.com/video-211232966_456241362
     *  - https://vk.com/video_ext.php?oid=-211232966&id=456241362&hash=...
     *
     * @return array{oid: string, id: string, hash: ?string}|null
     */
    public static function parseVideo(?string $url): ?array
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $hash = null;

        if (($query = parse_url($url, PHP_URL_QUERY)) !== null && $query !== false) {
            parse_str($query, $params);

            if (isset($params['hash']) && is_string($params['hash']) && $params['hash'] !== '') {
                $hash = $params['hash'];
            }

            // Формат embed: ?oid=...&id=...
            if (isset($params['oid'], $params['id']) && is_numeric($params['oid']) && is_numeric($params['id'])) {
                return [
                    'oid' => (string) $params['oid'],
                    'id' => (string) $params['id'],
                    'hash' => $hash,
                ];
            }
        }

        // Формат video-<oid>_<id> или video<oid>_<id>
        if (preg_match('/video(-?\d+)_(\d+)/', $url, $matches) === 1) {
            return [
                'oid' => $matches[1],
                'id' => $matches[2],
                'hash' => $hash,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.vk-video.preview', [
            'video' => self::parseVideo($config['url'] ?? null),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        $video = self::parseVideo($config['url'] ?? null);

        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.vk-video.index', [
            'oid' => $video['oid'] ?? null,
            'vkId' => $video['id'] ?? null,
            'hash' => $video['hash'] ?? null,
            'width' => is_numeric($config['width'] ?? null) ? (int) $config['width'] : null,
            'alignment' => $config['alignment'] ?? 'center',
        ])->render();
    }
}
