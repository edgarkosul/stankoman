<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use App\Support\WorkSchedule;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;

/**
 * Режим работы из настроек — вместо графика, набранного в тексте страницы.
 *
 * Набранный руками график расходится с шапкой при первой же правке часов
 * (так уже было: «О компании» 9–17, шапка 9–18). Блок хранит только своё
 * место на странице, а часы берёт у настройки `company.work_schedule`.
 */
class WorkScheduleBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'work-schedule';
    }

    public static function getLabel(): string
    {
        return 'Режим работы';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return view(
            'filament.forms.components.rich-editor.rich-content-custom-blocks.work-schedule.preview',
            static::resolveViewData(),
        )->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view(
            'filament.forms.components.rich-editor.rich-content-custom-blocks.work-schedule.index',
            static::resolveViewData(),
        )->render();
    }

    /**
     * @return array{lines: list<string>, note: string}
     */
    private static function resolveViewData(): array
    {
        $schedule = WorkSchedule::fromConfig();

        return [
            'lines' => $schedule->lines(),
            'note' => $schedule->note,
        ];
    }
}
