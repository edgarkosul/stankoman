<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_id')
                    ->label('Родительская категория')
                    ->options(fn (Get $get): array => self::categoryOptions(
                        self::normalizeParentId($get('parent_id'))
                    ))
                    ->default(fn () => self::resolveRequestedParentId())
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

                self::slugField(),

                TextInput::make('meta_title')
                    ->label('Meta title')
                    ->maxLength(255),

                Textarea::make('meta_description')
                    ->label('Meta description')
                    ->maxLength(255),

                Hidden::make('img'),

                View::make('filament.resources.categories.components.image-picker')
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Активна')
                    ->default(true),
            ]);
    }

    protected static function categoryOptions(?int $selectedParentId = null): array
    {
        $all = Category::query()
            ->availableAsParent()
            ->orderBy('parent_id')
            ->orderBy('order')
            ->get()
            ->groupBy('parent_id');

        $rootKey = Category::defaultParentKey();

        $out = [(string) $rootKey => 'Корень'];

        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $all) {
            foreach ($all[$parentId] ?? [] as $cat) {
                $out[$cat->id] = str_repeat('— ', $depth).$cat->name;
                $walk($cat->id, $depth + 1);
            }
        };

        $walk($rootKey, 0);

        $selectedParentId ??= self::resolveRequestedParentId();

        if ($selectedParentId !== $rootKey && ! array_key_exists($selectedParentId, $out)) {
            $requestedParent = Category::query()
                ->withoutStaging()
                ->find($selectedParentId);

            if ($requestedParent instanceof Category) {
                $out[$requestedParent->getKey()] = self::formatCategoryOptionLabel($requestedParent);
            }
        }

        return $out;
    }

    protected static function resolveRequestedParentId(): int
    {
        return self::normalizeParentId(request()->query('parent_id')) ?? Category::defaultParentKey();
    }

    protected static function normalizeParentId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $requestedParentId = (int) $value;

        if ($requestedParentId === Category::defaultParentKey()) {
            return $requestedParentId;
        }

        $parentExists = Category::query()
            ->withoutStaging()
            ->whereKey($requestedParentId)
            ->exists();

        return $parentExists ? $requestedParentId : null;
    }

    protected static function formatCategoryOptionLabel(Category $category): string
    {
        $depth = max($category->ancestorsAndSelf()->count() - 1, 0);

        return str_repeat('— ', $depth).$category->name;
    }

    public static function slugField(): TextInput
    {
        return TextInput::make('slug')
            ->label('Slug')
            ->required()
            ->scopedUnique(modifyQueryUsing: function (Builder $query, Get $get): Builder {
                $parentId = filled($get('parent_id'))
                    ? (int) $get('parent_id')
                    : Category::defaultParentKey();

                return $query->where('parent_id', $parentId);
            })
            ->validationMessages([
                'unique' => 'Категория с таким slug уже существует в выбранном родителе.',
            ])
            ->afterStateUpdated(function (Set $set) {
                // как только тронули slug руками — перестаём автогенерить (для create)
                $set('slug_manually_changed', true);
            });
    }
}
