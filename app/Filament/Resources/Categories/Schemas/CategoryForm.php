<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_id')
                    ->label('Родительская категория')
                    ->options(fn () => self::categoryOptions())
                    ->default(fn () => request()->integer('parent_id', -1))
                    ->searchable()
                    ->preload()
                    ->required(),

                // флаг, что slug уже трогали руками
                Hidden::make('slug_manually_changed')
                    ->default(false)
                    ->dehydrated(false),

                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (
                        ?Category $record, // null на create, модель на edit
                        Get $get,
                        Set $set,
                        ?string $state
                    ) {
                        // При редактировании никогда не трогаем slug
                        if ($record) {
                            return;
                        }

                        // Если юзер уже правил slug руками — не трогаем
                        if ($get('slug_manually_changed')) {
                            return;
                        }

                        if (! filled($state)) {
                            return;
                        }

                        $set('slug', Str::slug($state));
                    }),

                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->afterStateUpdated(function (Set $set) {
                        // как только тронули slug руками — перестаём автогенерить (для create)
                        $set('slug_manually_changed', true);
                    }),

                Textarea::make('meta_description')
                    ->label('Meta description')
                    ->maxLength(255),

                FileUpload::make('img')
                    ->label('Изображение для категории')
                    ->disk('public')
                    ->imageEditor()
                    ->imageEditorAspectRatios([
                        '16:9',
                        '4:3',
                        '1:1',
                    ])
                    ->directory('pics'),

                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true),
            ]);
    }

    protected static function categoryOptions(): array
    {
        $all = Category::query()
            ->availableAsParent()
            ->orderBy('parent_id')
            ->orderBy('order')
            ->get()
            ->groupBy('parent_id');

        $out = ['-1' => 'Корень'];

        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $all) {
            foreach ($all[$parentId] ?? [] as $cat) {
                $out[$cat->id] = str_repeat('— ', $depth).$cat->name;
                $walk($cat->id, $depth + 1);
            }
        };

        $walk(-1, 0);

        return $out;
    }
}
