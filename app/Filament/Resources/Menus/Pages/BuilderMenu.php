<?php

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Resources\Menus\MenuResource;
use App\Models\MenuItem;
use App\Models\Page as StaticPage;
use App\Support\Menu\MenuService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Route;

class BuilderMenu extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = MenuResource::class;
    protected static ?string $title = 'Конструктор меню';

    protected string $view = 'filament.resources.menus.pages.builder-menu';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->form->fill([
            'items' => $this->getItemsState(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Repeater::make('items')
                    ->label('1-й уровень')
                    ->reorderable()
                    ->default([])
                    ->itemLabel(fn (array $state): string => $state['label'] ?? 'Пункт меню')
                    ->collapsible()
                    ->collapsed()
                    ->schema($this->menuItemFields(withChildren: true)),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Сохранить')
                ->icon('heroicon-o-check')
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $items = $state['items'] ?? [];

        $this->syncLevel($items, parentId: null, depth: 0);
        app(MenuService::class)->forget($this->record->key);

        Notification::make()
            ->title('Меню сохранено')
            ->success()
            ->send();
    }

    /**
     * Поля пункта меню.
     * withChildren=true -> добавляет Repeater дочерних пунктов (2-й уровень)
     */
    private function menuItemFields(bool $withChildren): array
    {
        $fields = [
            Hidden::make('id'),
            Hidden::make('has_children'),

            TextInput::make('label')
                ->label('Название')
                ->required()
                ->maxLength(200),

            Select::make('type')
                ->label('Тип')
                ->options([
                    'page' => 'Статичная страница',
                    'route' => 'Маршрут Laravel',
                    'url' => 'Внешний URL',
                ])
                ->required(fn (Get $get) => ! $this->hasChildrenState($get))
                ->disabled(fn (Get $get) => $this->hasChildrenState($get))
                ->helperText(fn (Get $get) => $this->hasChildrenState($get)
                    ? 'У пункта с подпунктами ссылка отключена.'
                    : null)
                ->live(),

            Select::make('page_id')
                ->label('Страница')
                ->options(fn () => StaticPage::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->preload()
                ->visible(fn (Get $get) => $get('type') === 'page' && ! $this->hasChildrenState($get))
                ->required(fn (Get $get) => $get('type') === 'page' && ! $this->hasChildrenState($get)),

            Select::make('route_name')
                ->label('Route name')
                ->options(fn () => $this->routeOptions())
                ->searchable()
                ->visible(fn (Get $get) => $get('type') === 'route' && ! $this->hasChildrenState($get))
                ->required(fn (Get $get) => $get('type') === 'route' && ! $this->hasChildrenState($get)),

            KeyValue::make('route_params')
                ->label('Route params')
                ->keyLabel('Key')
                ->valueLabel('Value')
                ->visible(fn (Get $get) => $get('type') === 'route' && ! $this->hasChildrenState($get)),

            TextInput::make('url')
                ->label('URL')
                ->placeholder('https://example.com')
                ->visible(fn (Get $get) => $get('type') === 'url' && ! $this->hasChildrenState($get))
                ->required(fn (Get $get) => $get('type') === 'url' && ! $this->hasChildrenState($get))
                ->maxLength(2048),

            Toggle::make('is_active')
                ->label('Активен')
                ->default(true),

            Select::make('target')
                ->label('Открывать')
                ->options([
                    null => 'В этой вкладке',
                    '_blank' => 'В новой вкладке',
                ])
                ->disabled(fn (Get $get) => $this->hasChildrenState($get)),

            TextInput::make('rel')
                ->label('rel')
                ->placeholder('nofollow noopener')
                ->helperText('служебные теги ссылки (через пробел). Не знаете — оставьте пустым. Часто: noopener noreferrer + при необходимости nofollow')
                ->disabled(fn (Get $get) => $this->hasChildrenState($get)),
        ];

        if ($withChildren) {
            $fields[] = Repeater::make('children')
                ->label('2-й уровень')
                ->reorderable()
                ->default([])
                ->itemLabel(fn (array $state): string => $state['label'] ?? 'Подпункт')
                ->collapsible()
                ->collapsed()
                ->schema($this->menuItemFields(withChildren: false));
        }

        return $fields;
    }

    private function routeOptions(): array
    {
        $allowed = (array) config('menu.allowed_routes', []);
        $options = [];

        foreach ($allowed as $name) {
            if (is_string($name) && $name !== '' && Route::has($name)) {
                $options[$name] = $name;
            }
        }

        return $options;
    }

    private function getItemsState(): array
    {
        $roots = MenuItem::query()
            ->where('menu_id', $this->record->id)
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->withCount('children')
            ->with(['children' => fn ($q) => $q->orderBy('sort')->withCount('children')])
            ->get();

        return $roots->map(fn (MenuItem $item) => $this->toState($item, withChildren: true))->all();
    }

    private function toState(MenuItem $item, bool $withChildren): array
    {
        return [
            'id' => $item->id,
            'label' => $item->label,
            'type' => $item->type,
            'has_children' => $item->hasChildren(),

            'url' => $item->url,
            'route_name' => $item->route_name,
            'route_params' => $item->route_params,
            'page_id' => $item->page_id,

            'is_active' => (bool) $item->is_active,
            'target' => $item->target,
            'rel' => $item->rel,

            'children' => $withChildren
                ? $item->children->map(fn (MenuItem $child) => $this->toState($child, withChildren: false))->all()
                : [],
        ];
    }

    /**
     * Синхронизируем уровень дерева.
     * depth=0 -> root, depth=1 -> children. Глубже не сохраняем.
     */
    private function syncLevel(array $items, ?int $parentId, int $depth): void
    {
        if ($depth > 1) {
            return;
        }

        $keptIds = [];

        foreach (array_values($items) as $index => $item) {
            $id = $item['id'] ?? null;

            $payload = $this->normalizePayload($item);
            $payload['menu_id'] = $this->record->id;
            $payload['parent_id'] = $parentId;
            $payload['sort'] = ($index + 1) * 10;

            if ($id) {
                $model = MenuItem::query()
                    ->whereKey($id)
                    ->where('menu_id', $this->record->id)
                    ->first();

                if ($model) {
                    $model->update($payload);
                } else {
                    // если вдруг прилетел id не из этого меню — создадим как новый
                    $model = MenuItem::create($payload);
                }
            } else {
                $model = MenuItem::create($payload);
            }

            $keptIds[] = $model->id;

            // children сохраняем только для depth=0 (это и есть ограничение 2 уровней)
            if ($depth === 0) {
                $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                $this->syncLevel($children, parentId: $model->id, depth: 1);
            }
        }

        // удаляем то, чего больше нет на этом уровне
        $query = MenuItem::query()
            ->where('menu_id', $this->record->id)
            ->where('parent_id', $parentId);

        if (count($keptIds)) {
            $query->whereNotIn('id', $keptIds);
        }

        $query->delete();
    }

    private function normalizePayload(array $item): array
    {
        $type = $item['type'] ?? 'page';
        $hasChildren = $this->itemHasChildren($item);

        $payload = [
            'label' => (string) ($item['label'] ?? ''),
            'type' => $type,
            'is_active' => (bool) ($item['is_active'] ?? true),
            'target' => $hasChildren ? null : ($item['target'] ?? null),
            'rel' => $hasChildren ? null : ($item['rel'] ?? null),
        ];

        // Сбрасываем неактуальные поля — чтобы база была чистой
        $payload['url'] = null;
        $payload['route_name'] = null;
        $payload['route_params'] = null;
        $payload['page_id'] = null;

        if ($hasChildren) {
            return $payload;
        }

        if ($type === 'url') {
            $payload['url'] = (string) ($item['url'] ?? '');
        }

        if ($type === 'route') {
            $payload['route_name'] = (string) ($item['route_name'] ?? '');
            $params = $item['route_params'] ?? null;
            $payload['route_params'] = (is_array($params) && count($params)) ? $params : null;
        }

        if ($type === 'page') {
            $payload['page_id'] = $item['page_id'] ?? null;
        }

        return $payload;
    }

    private function hasChildrenState(Get $get): bool
    {
        $children = $get('children');
        if (is_array($children)) {
            return count($children) > 0;
        }

        return (bool) $get('has_children');
    }

    private function itemHasChildren(array $item): bool
    {
        $children = $item['children'] ?? null;

        if (is_array($children)) {
            return count($children) > 0;
        }

        return (bool) ($item['has_children'] ?? false);
    }
}
