<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Seeder;

class PrimaryMenuSeeder extends Seeder
{
    public function run(): void
    {
        $menu = Menu::firstOrCreate(
            ['key' => 'primary'],
            ['name' => 'Основное меню']
        );

        $tree = [
            [
                'label' => 'Оформление и доставка',
                'slug' => 'delivery',
            ],
            [
                'label' => 'Производство',
                'slug' => 'production',
                'children' => [
                    ['label' => 'Каталог продукции', 'slug' => 'production-catalog'],
                    ['label' => 'Наши мощности', 'slug' => 'production-capabilities'],
                ],
            ],
            [
                'label' => 'Сервисное обслуживание',
                'slug' => 'service',
                'children' => [
                    ['label' => 'Ремонт и диагностика', 'slug' => 'service-repair'],
                    ['label' => 'Запчасти', 'slug' => 'service-spares'],
                ],
            ],
            [
                'label' => 'Контакты',
                'slug' => 'contacts',
            ],
            [
                'label' => 'Оплата',
                'slug' => 'payment',
            ],
            [
                'label' => 'Гарантия',
                'slug' => 'warranty',
            ],
        ];

        foreach ($tree as $i => $root) {
            $rootPage = $this->ensurePage($root['label'], $root['slug']);

            $parent = $this->ensureMenuItem(
                menuId: $menu->id,
                parentId: null,
                label: $root['label'],
                pageId: $rootPage->id,
                sort: ($i + 1) * 10,
            );

            foreach (($root['children'] ?? []) as $j => $child) {
                $childPage = $this->ensurePage($child['label'], $child['slug']);

                $this->ensureMenuItem(
                    menuId: $menu->id,
                    parentId: $parent->id,
                    label: $child['label'],
                    pageId: $childPage->id,
                    sort: ($j + 1) * 10,
                );
            }
        }
    }

    private function ensurePage(string $title, string $slug): Page
    {
        $page = Page::query()->where('slug', $slug)->first();

        if ($page) {
            if ($page->title === '' || $page->title === null) {
                $page->update(['title' => $title]);
            }

            return $page;
        }

        return Page::create([
            'title' => $title,
            'slug' => $slug,
            'content' => null,
            'is_published' => true,
            'published_at' => now(),
            'meta_title' => $title,
            'meta_description' => null,
        ]);
    }

    private function ensureMenuItem(
        int $menuId,
        ?int $parentId,
        string $label,
        int $pageId,
        int $sort
    ): MenuItem {
        $item = MenuItem::query()
            ->where('menu_id', $menuId)
            ->where('parent_id', $parentId)
            ->where('label', $label)
            ->first();

        if ($item) {
            if ($item->type !== 'page' || $item->page_id === null) {
                $item->update([
                    'type' => 'page',
                    'page_id' => $pageId,
                    'url' => null,
                    'route_name' => null,
                    'route_params' => null,
                ]);
            }

            return $item;
        }

        return MenuItem::create([
            'menu_id' => $menuId,
            'parent_id' => $parentId,
            'label' => $label,
            'type' => 'page',
            'page_id' => $pageId,
            'sort' => $sort,
            'is_active' => true,
            'target' => null,
            'rel' => null,
        ]);
    }
}
