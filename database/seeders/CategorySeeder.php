<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    private ?array $picsPool = null;
    private bool $picsWarned = false;

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
            'img' => $this->categoryImage(),
            'is_active' => true,
            'order' => $order,
            'meta_description' => "Раздел {$name} в каталоге оборудования.",
        ]);
    }

    private function categoryImage(): ?string
    {
        return $this->randomPic();
    }

    private function categoryTree(): array
    {
        return [
            [
                'name' => 'Компрессоры',
                'children' => $this->leafNodes([
                    'Поршневые',
                    'Винтовые',
                    'Безмасляные',
                    'Ременные',
                    'Передвижные',
                    'Стационарные',
                    'Высокого давления',
                    'Низкого давления',
                    'С ресивером',
                    'Осушители воздуха',
                ]),
            ],
            [
                'name' => 'Пневмоинструмент',
                'children' => $this->leafNodes([
                    'Гайковерты',
                    'Шлифмашины',
                    'Дрели',
                    'Пистолеты продувочные',
                    'Краскопульты',
                    'Пневмостеплеры',
                    'Пескоструйные пистолеты',
                    'Заклепочники',
                    'Блоки подготовки воздуха',
                ]),
            ],
            [
                'name' => 'Сварочное оборудование',
                'children' => $this->leafNodes([
                    'Инверторы MMA',
                    'Полуавтоматы MIG/MAG',
                    'Аргонодуговые TIG',
                    'Плазморезы',
                    'Точечная сварка',
                    'Сварочные генераторы',
                    'Пуско-зарядные устройства',
                    'Сварочные горелки',
                    'Проволока и электроды',
                    'Маски и щитки',
                    'Редукторы и регуляторы',
                ]),
            ],
            [
                'name' => 'Электроинструмент',
                'children' => $this->leafNodes([
                    'Дрели и шуруповерты',
                    'Перфораторы',
                    'УШМ (болгарки)',
                    'Лобзики',
                    'Дисковые пилы',
                    'Сабельные пилы',
                    'Рубанки',
                    'Фены строительные',
                    'Шлифмашины',
                    'Наборы инструмента',
                ]),
            ],
            [
                'name' => 'Генераторы и электростанции',
                'children' => $this->leafNodes([
                    'Бензиновые',
                    'Дизельные',
                    'Инверторные',
                    'Сварочные',
                    'Трехфазные',
                    'Однофазные',
                    'Автозапуск',
                    'Стабилизаторы напряжения',
                ]),
            ],
            [
                'name' => 'Насосное оборудование',
                'children' => $this->leafNodes([
                    'Поверхностные насосы',
                    'Скважинные насосы',
                    'Дренажные насосы',
                    'Фекальные насосы',
                    'Насосные станции',
                    'Циркуляционные насосы',
                    'Повышающие давление',
                    'Пожарные насосы',
                    'Мотопомпы',
                    'Гидроаккумуляторы',
                    'Автоматика и реле',
                    'Шланги и фитинги',
                ]),
            ],
            [
                'name' => 'Строительное оборудование',
                'children' => $this->leafNodes([
                    'Бетономешалки',
                    'Виброплиты',
                    'Вибраторы глубинные',
                    'Штукатурные станции',
                    'Растворосмесители',
                    'Затирочные машины',
                    'Лебедки строительные',
                    'Тепловые пушки',
                    'Опалубка и леса',
                ]),
            ],
            [
                'name' => 'Подъемное оборудование',
                'children' => $this->leafNodes([
                    'Тали электрические',
                    'Тали ручные',
                    'Лебедки',
                    'Домкраты',
                    'Краны гидравлические',
                    'Подъемные столы',
                    'Стропы и цепи',
                    'Траверсы',
                    'Тележки гидравлические',
                    'Кран-балки',
                ]),
            ],
            [
                'name' => 'Садовая техника',
                'children' => $this->leafNodes([
                    'Газонокосилки',
                    'Триммеры',
                    'Кусторезы',
                    'Снегоуборщики',
                    'Культиваторы',
                    'Мотоблоки',
                    'Садовые пылесосы',
                    'Опрыскиватели',
                    'Дровоколы',
                    'Измельчители веток',
                    'Поливочные системы',
                ]),
            ],
            [
                'name' => 'Оснастка и расходники',
                'children' => $this->leafNodes([
                    'Сверла',
                    'Буры',
                    'Диски отрезные',
                    'Круги шлифовальные',
                    'Насадки и биты',
                    'Абразивные ленты',
                    'Сверлильные коронки',
                    'Пилки',
                    'Фрезы',
                    'Клей и герметики',
                ]),
            ],
            [
                'name' => 'Климат и вентиляция',
                'children' => $this->leafNodes([
                    'Обогреватели',
                    'Тепловые пушки',
                    'Вентиляторы',
                    'Осушители воздуха',
                    'Увлажнители воздуха',
                    'Кондиционеры мобильные',
                    'Вытяжные системы',
                    'Воздушные завесы',
                    'Теплообменники',
                ]),
            ],
            [
                'name' => 'Освещение',
                'children' => $this->leafNodes([
                    'Прожекторы',
                    'Светодиодные панели',
                    'Ленты LED',
                    'Переносные светильники',
                    'Аккумуляторные фонари',
                    'Светильники для гаража',
                    'Уличные светильники',
                    'Лампы',
                    'Патроны и цоколи',
                    'Автономные светильники',
                    'Штативы и крепления',
                    'Датчики движения',
                ]),
            ],
            [
                'name' => 'Автосервис и гараж',
                'children' => $this->leafNodes([
                    'Подъемники',
                    'Шиномонтаж',
                    'Компрессоры для сервиса',
                    'Балансировочные станки',
                    'Диагностические сканеры',
                    'Пуско-зарядные устройства',
                    'Моечное оборудование',
                    'Инструмент для автосервиса',
                ]),
            ],
            [
                'name' => 'Измерительный инструмент',
                'children' => $this->leafNodes([
                    'Лазерные уровни',
                    'Дальномеры',
                    'Рулетки',
                    'Мультиметры',
                    'Токоизмерительные клещи',
                    'Термометры',
                    'Манометры',
                    'Влагомеры',
                    'Штангенциркули',
                    'Угломеры',
                ]),
            ],
            [
                'name' => 'Средства защиты',
                'children' => $this->leafNodes([
                    'Очки защитные',
                    'Перчатки',
                    'Респираторы',
                    'Наушники противошумные',
                    'Каски',
                    'Спецодежда',
                    'Обувь защитная',
                    'Сигнальные жилеты',
                    'Накидки сварщика',
                    'Аптечки',
                    'Средства от падения',
                ]),
            ],
        ];
    }

    private function leafNodes(array $names): array
    {
        return array_map(static fn (string $name) => ['name' => $name], $names);
    }

    private function randomPic(): ?string
    {
        $pool = $this->picPool();
        if ($pool === []) {
            if (! $this->picsWarned) {
                $this->command?->warn('Папка storage/app/public/pics пуста — изображения категорий будут пустыми.');
                $this->picsWarned = true;
            }

            return null;
        }

        return $pool[array_rand($pool)];
    }

    private function picPool(): array
    {
        if ($this->picsPool !== null) {
            return $this->picsPool;
        }

        $files = Storage::disk('public')->files('pics');
        $files = array_values(array_filter($files, static function (string $path) {
            return (bool) preg_match('/\\.(jpe?g|png|webp|gif)$/i', $path);
        }));

        return $this->picsPool = $files;
    }
}
