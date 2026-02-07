<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use App\Models\Slider;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;

class HeroSliderBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'hero-slider';
    }

    public static function getLabel(): string
    {
        return 'Слайдер';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Вставка Hero-слайдера')
            ->modalDescription('Выберите один из существующих слайдеров.')
            ->schema([
                Select::make('slider_id')
                    ->label('Слайдер')
                    ->options(fn() => Slider::query()
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        $slider = null;
        if (!empty($config['slider_id'])) {
            $slider = Slider::find($config['slider_id']);
        }

        $slidesCount = $slider ? count($slider->slides ?? []) : 0;

        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.hero-slider.preview', [
            'slider'      => $slider,
            'slidesCount' => $slidesCount,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        $slider = null;
        if (!empty($config['slider_id'])) {
            $slider = Slider::find($config['slider_id']);
        }

        if (! $slider) {
            return '';
        }

        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.hero-slider.index', [
            'slider' => $slider,
        ])->render();
    }
}
