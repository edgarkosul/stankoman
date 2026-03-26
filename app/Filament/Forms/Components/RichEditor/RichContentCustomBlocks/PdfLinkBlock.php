<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use App\Support\Filament\PdfLinkBlockConfigNormalizer;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfLinkBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'pdf-link';
    }

    public static function getLabel(): string
    {
        return 'PDF документ';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('PDF документ')
            ->modalDescription('Загрузите PDF с компьютера, скачайте его по URL в локальное хранилище или вставьте внешнюю ссылку без скачивания.')
            ->mutateDataUsing(fn (array $data): array => app(PdfLinkBlockConfigNormalizer::class)->normalize($data))
            ->schema([
                ToggleButtons::make('source_type')
                    ->label('Источник PDF')
                    ->options([
                        PdfLinkBlockConfigNormalizer::SOURCE_UPLOAD => 'С компьютера',
                        PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL => 'Скачать по URL',
                        PdfLinkBlockConfigNormalizer::SOURCE_DIRECT_URL => 'Внешний URL',
                    ])
                    ->default(PdfLinkBlockConfigNormalizer::SOURCE_UPLOAD)
                    ->grouped()
                    ->live()
                    ->required(),
                FileUpload::make('file')
                    ->label('PDF-файл')
                    ->acceptedFileTypes(['application/pdf'])
                    ->disk(PdfLinkBlockConfigNormalizer::DISK)
                    ->directory(PdfLinkBlockConfigNormalizer::DIRECTORY)
                    ->maxSize(PdfLinkBlockConfigNormalizer::MAX_FILE_SIZE_KB)
                    ->storeFileNamesIn('original_file_name')
                    ->helperText('Файл будет храниться локально и открываться по публичной ссылке.')
                    ->visible(fn (Get $get): bool => $get('source_type') === PdfLinkBlockConfigNormalizer::SOURCE_UPLOAD)
                    ->required(fn (Get $get): bool => $get('source_type') === PdfLinkBlockConfigNormalizer::SOURCE_UPLOAD),
                TextInput::make('url')
                    ->label('URL PDF')
                    ->url()
                    ->maxLength(2048)
                    ->helperText(function (Get $get): string {
                        return $get('source_type') === PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL
                            ? 'Файл будет скачан по ссылке и сохранён локально.'
                            : 'Ссылка останется внешней и будет открываться напрямую.';
                    })
                    ->visible(fn (Get $get): bool => in_array($get('source_type'), [
                        PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL,
                        PdfLinkBlockConfigNormalizer::SOURCE_DIRECT_URL,
                    ], true))
                    ->required(fn (Get $get): bool => in_array($get('source_type'), [
                        PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL,
                        PdfLinkBlockConfigNormalizer::SOURCE_DIRECT_URL,
                    ], true)),
                TextInput::make('link_text')
                    ->label('Текст ссылки')
                    ->maxLength(255)
                    ->helperText('Если оставить пустым, текст будет взят из имени файла или URL.'),
                ToggleButtons::make('target')
                    ->label('Открывать')
                    ->options([
                        PdfLinkBlockConfigNormalizer::TARGET_SAME_TAB => 'В этой вкладке',
                        PdfLinkBlockConfigNormalizer::TARGET_NEW_TAB => 'В новой вкладке',
                    ])
                    ->default(PdfLinkBlockConfigNormalizer::TARGET_NEW_TAB)
                    ->grouped()
                    ->required(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $linkText = static::resolveLinkText($config);

        return 'PDF: '.Str::limit($linkText, 48);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.pdf-link.preview', [
            'href' => static::resolveHref($config),
            'linkText' => static::resolveLinkText($config),
            'sourceLabel' => static::resolveSourceLabel($config),
            'targetLabel' => static::resolveTargetLabel($config),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.pdf-link.index', [
            'href' => static::resolveHref($config),
            'linkText' => static::resolveLinkText($config),
            'shouldOpenInNewTab' => static::resolveTarget($config) === PdfLinkBlockConfigNormalizer::TARGET_NEW_TAB,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveHref(array $config): ?string
    {
        $sourceType = static::sanitizeString($config['source_type'] ?? null);
        $file = static::sanitizeString($config['file'] ?? null);
        $url = static::sanitizeString($config['url'] ?? null);

        if (($sourceType !== PdfLinkBlockConfigNormalizer::SOURCE_DIRECT_URL) && $file !== null) {
            $storagePath = static::normalizeStoragePath($file);

            if (($storagePath !== null) && Storage::disk(PdfLinkBlockConfigNormalizer::DISK)->exists($storagePath)) {
                return Storage::disk(PdfLinkBlockConfigNormalizer::DISK)->url($storagePath);
            }

            $fileUrl = static::toUrl($file);

            if ($fileUrl !== null) {
                return $fileUrl;
            }
        }

        if ($url !== null) {
            return $url;
        }

        return $file !== null ? static::toUrl($file) : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveLinkText(array $config): string
    {
        $linkText = static::sanitizeString($config['link_text'] ?? null);

        if ($linkText !== null) {
            return $linkText;
        }

        foreach ([
            static::sanitizeString($config['url'] ?? null),
            static::sanitizeString($config['file'] ?? null),
        ] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $path = (string) parse_url($candidate, PHP_URL_PATH);
            $fileName = basename($path !== '' ? $path : $candidate);
            $fileName = trim(urldecode($fileName));

            if ($fileName !== '') {
                return $fileName;
            }
        }

        return 'PDF документ';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveSourceLabel(array $config): string
    {
        return match (static::sanitizeString($config['source_type'] ?? null)) {
            PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL => 'Локальная копия по URL',
            PdfLinkBlockConfigNormalizer::SOURCE_DIRECT_URL => 'Внешняя ссылка',
            default => 'Локальный файл',
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveTarget(array $config): string
    {
        return static::sanitizeString($config['target'] ?? null) === PdfLinkBlockConfigNormalizer::TARGET_SAME_TAB
            ? PdfLinkBlockConfigNormalizer::TARGET_SAME_TAB
            : PdfLinkBlockConfigNormalizer::TARGET_NEW_TAB;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveTargetLabel(array $config): string
    {
        return static::resolveTarget($config) === PdfLinkBlockConfigNormalizer::TARGET_SAME_TAB
            ? 'Открывается в текущей вкладке'
            : 'Открывается в новой вкладке';
    }

    private static function toUrl(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://', '/'])) {
            return $value;
        }

        if (Str::startsWith($value, 'storage/')) {
            return '/'.$value;
        }

        return Storage::disk(PdfLinkBlockConfigNormalizer::DISK)->url($value);
    }

    private static function normalizeStoragePath(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (Str::startsWith($value, '/storage/')) {
            return Str::after($value, '/storage/');
        }

        if (Str::startsWith($value, 'storage/')) {
            return Str::after($value, 'storage/');
        }

        if (Str::startsWith($value, ['http://', 'https://', '/'])) {
            return null;
        }

        return $value;
    }

    private static function sanitizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
