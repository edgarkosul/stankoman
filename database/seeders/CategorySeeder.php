<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->resetCategories();

        $tree = $this->categoryTree();

        foreach ($tree as $rootIndex => $root) {
            $rootCategory = $this->createCategory(
                parentId: Category::defaultParentKey(),
                name: $root['name'],
                order: $rootIndex + 1,
            );

            $this->seedChildren($rootCategory, $root['children'] ?? []);
        }
    }

    private function resetCategories(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::table('category_attribute')->truncate();
        DB::table('product_categories')->truncate();
        DB::table('categories')->truncate();

        Schema::enableForeignKeyConstraints();
    }

    private function seedChildren(Category $parent, array $children): void
    {
        foreach ($children as $index => $node) {
            $category = $this->createCategory(
                parentId: $parent->getKey(),
                name: $node['name'],
                order: $index + 1,
            );

            if (! empty($node['children'])) {
                $this->seedChildren($category, $node['children']);
            }
        }
    }

    private function createCategory(int $parentId, string $name, int $order): Category
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = Str::slug(Str::ascii($name));
        }

        if ($slug === '') {
            $slug = 'category-' . Str::lower(Str::random(6));
        }

        return Category::create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'img' => $this->categoryImage($slug),
            'is_active' => true,
            'order' => $order,
            'meta_description' => "Раздел {$name} в каталоге оборудования.",
        ]);
    }

    private function categoryImage(string $slug): string
    {
        return "https://picsum.photos/seed/cat-{$slug}/640/420";
    }

    private function categoryTree(): array
    {
        return [
            [
                'name' => 'Компрессоры',
                'children' => [
                    [
                        'name' => 'Поршневые',
                        'children' => [
                            ['name' => 'Ременные'],
                        ],
                    ],
                    [
                        'name' => 'Винтовые',
                        'children' => [
                            ['name' => 'Маслозаполненные'],
                        ],
                    ],
                    ['name' => 'Безмасляные'],
                ],
            ],
            [
                'name' => 'Пневмоинструмент',
                'children' => [
                    ['name' => 'Гайковерты'],
                    [
                        'name' => 'Шлифмашины',
                        'children' => [
                            ['name' => 'Угловые'],
                        ],
                    ],
                    [
                        'name' => 'Пневмоподготовка',
                        'children' => [
                            ['name' => 'Фильтры воздуха'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Сварочное оборудование',
                'children' => [
                    [
                        'name' => 'Инверторы',
                        'children' => [
                            ['name' => 'MMA'],
                        ],
                    ],
                    [
                        'name' => 'Полуавтоматы',
                        'children' => [
                            ['name' => 'MIG/MAG'],
                        ],
                    ],
                    ['name' => 'Плазморезы'],
                ],
            ],
            [
                'name' => 'Электроинструмент',
                'children' => [
                    [
                        'name' => 'Дрели и шуруповерты',
                        'children' => [
                            ['name' => 'Аккумуляторные'],
                        ],
                    ],
                    [
                        'name' => 'Пилы',
                        'children' => [
                            ['name' => 'Дисковые'],
                        ],
                    ],
                    ['name' => 'Перфораторы'],
                ],
            ],
            [
                'name' => 'Оснастка и расходники',
                'children' => [
                    [
                        'name' => 'Диски и круги',
                        'children' => [
                            ['name' => 'Отрезные'],
                        ],
                    ],
                    ['name' => 'Сверла'],
                ],
            ],
            [
                'name' => 'Автосервис и гараж',
                'children' => [
                    [
                        'name' => 'Домкраты',
                        'children' => [
                            ['name' => 'Гидравлические'],
                        ],
                    ],
                    ['name' => 'Пуско-зарядные устройства'],
                ],
            ],
            [
                'name' => 'Климат и вентиляция',
                'children' => [
                    [
                        'name' => 'Обогреватели',
                        'children' => [
                            ['name' => 'Конвекторы'],
                        ],
                    ],
                    ['name' => 'Тепловые пушки'],
                ],
            ],
            [
                'name' => 'Освещение',
                'children' => [
                    [
                        'name' => 'Прожекторы',
                        'children' => [
                            ['name' => 'Светодиодные'],
                        ],
                    ],
                    ['name' => 'Аккумуляторные фонари'],
                ],
            ],
        ];
    }
}
