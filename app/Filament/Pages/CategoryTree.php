<?php

namespace App\Filament\Pages;

use App\Models\Category;
use Illuminate\Support\Str;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use SolutionForest\FilamentTree\Actions\Action;
use SolutionForest\FilamentTree\Pages\TreePage;
use SolutionForest\FilamentTree\Actions\EditAction;
use SolutionForest\FilamentTree\Actions\ViewAction;
use SolutionForest\FilamentTree\Actions\DeleteAction;
use App\Filament\Resources\Categories\CategoryResource;

class CategoryTree extends TreePage
{
    protected static string $model = Category::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static int $maxDepth = 5;

    protected static ?string $navigationLabel = 'Дерево категорий';

    protected static ?string $title           = 'Дерево категорий';


    public function getNodeCollapsedState(?\Illuminate\Database\Eloquent\Model $record = null): bool
    {
        return false;
    }



    protected function getTreeToolbarActions(): array
    {
        return [];
    }

    protected function getActions(): array
    {
        return [
            $this->getCreateAction(),
            // SAMPLE CODE, CAN DELETE
            //\Filament\Pages\Actions\Action::make('sampleAction'),
        ];
    }

    protected function getFormSchema(): array
    {
        return [
            Select::make('parent_id')
                ->label('Родительская категория')
                ->options(fn() => self::categoryOptions())
                ->default(fn() => request()->integer('parent_id', -1))
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
        ];
    }

    // protected function hasDeleteAction(): bool
    // {
    //     return true;
    // }

    protected function hasEditAction(): bool
    {
        return false;
    }

    // protected function hasViewAction(): bool
    // {
    //     return false;
    // }



    protected function getHeaderWidgets(): array
    {
        return [];
    }

    protected function getFooterWidgets(): array
    {
        return [];
    }

    public function getTreeRecordTitle(?\Illuminate\Database\Eloquent\Model $record = null): string
    {
        if (!$record) return '';

        return "{$record->name}";
    }

    protected function getTreeActions(): array
    {
        return [
            Action::make('createChild')
                ->icon('heroicon-o-folder-plus')
                ->iconButton()
                ->hiddenLabel()
                ->tooltip('Создать подкатегорию')
                ->visible(
                    fn(Category $record): bool =>
                    $this->recordDepth($record) < static::$maxDepth - 1
                )
                ->action(function (Category $record): void {
                    // вычисляем order для нового ребёнка
                    $maxOrder = Category::query()
                        ->where('parent_id', $record->getKey())
                        ->max('order');

                    $order = is_null($maxOrder) ? 0 : $maxOrder + 1;

                    // простой уникальный временный slug
                    $slug = Str::slug('new-category-' . Str::random(6));

                    Category::create([
                        'parent_id' => $record->getKey(),
                        'order'     => $order,
                        'name'      => 'Новая категория',
                        'slug'      => $slug,
                        'is_active' => true,
                    ]);
                }),
            Action::make('editInResource')
                ->icon('heroicon-c-pencil-square') // или 'heroicon-o-pencil-square'
                ->iconButton()   // только иконка, без синей «таблетки»
                ->hiddenLabel()  // без текста, останется тултип
                ->tooltip('Редактировать категорию')
                ->url(
                    fn(Category $record): string =>
                    CategoryResource::getUrl('edit', ['record' => $record])
                ),

            DeleteAction::make(),

            // кастомный пример — “Открыть на сайте”
            Action::make('openFront')
                ->iconButton()
                ->tooltip('Открыть на сайте')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn(Category $record) => route('catalog.leaf', ['path' => $record->slug_path]), true),

        ];
    }

    protected static function categoryOptions(): array
    {
        // Заберём всё дерево и развернём в плоский список с depth
        $all = Category::query()
            ->orderBy('parent_id')
            ->orderBy('order')
            ->get()
            ->groupBy('parent_id');

        $out = ['-1' => 'Корень'];

        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $all) {
            foreach ($all[$parentId] ?? [] as $cat) {
                $out[$cat->id] = str_repeat('— ', $depth) . $cat->name;
                $walk($cat->id, $depth + 1);
            }
        };

        $walk(-1, 0);

        return $out;
    }

    protected function recordDepth(Category $record): int
    {
        // [root, ..., current] → depth = count - 1
        return $record->ancestorsAndSelf()->count() - 1;
    }



    // CUSTOMIZE ICON OF EACH RECORD, CAN DELETE
    // public function getTreeRecordIcon(?\Illuminate\Database\Eloquent\Model $record = null): ?string
    // {
    //     return null;
    // }
}
