<?php

namespace Database\Seeders;

use App\Enums\ProductWarranty;
use App\Models\Category;
use App\Models\Product;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProductSeeder extends Seeder
{
    private ?array $picsPool = null;

    private bool $picsWarned = false;

    public function run(): void
    {
        $leafCategories = Category::query()->leaf()->orderBy('id')->get();

        if ($leafCategories->isEmpty()) {
            $this->command?->warn('Не найдено листовых категорий — сначала запустите CategorySeeder.');

            return;
        }

        $this->resetProducts();

        $faker = FakerFactory::create('ru_RU');

        $brands = [
            'AeroPro',
            'NordWerk',
            'Fortech',
            'Sturm',
            'Zitrek',
            'Patriot',
            'Metabo',
            'Bosch',
            'Makita',
            'Denzel',
            'Kraftool',
            'Fubag',
            'Resanta',
            'Wortex',
        ];

        $countries = [
            'Россия',
            'Германия',
            'Китай',
            'Италия',
            'Турция',
            'Польша',
        ];

        $promoLines = [
            'Хит продаж',
            'Новинка сезона',
            'Скидка недели',
            'Лимитированная партия',
            'Выгодный комплект',
        ];

        $warranties = array_map(
            static fn (ProductWarranty $warranty): string => $warranty->value,
            ProductWarranty::cases()
        );

        $minPerLeaf = 80;
        $maxPerLeaf = 120;

        $globalIndex = 1;

        foreach ($leafCategories as $category) {
            $count = $faker->numberBetween($minPerLeaf, $maxPerLeaf);

            for ($i = 0; $i < $count; $i++) {
                $brand = $faker->randomElement($brands);
                $model = strtoupper($faker->bothify('??-###'));
                $name = "{$category->name} {$brand} {$model}";

                $price = $faker->numberBetween(2000, 250000);
                $discount = $faker->boolean(25)
                    ? $this->discountFrom($price, $faker)
                    : null;

                $inStock = $faker->boolean(85);
                $qty = $inStock ? $faker->numberBetween(1, 200) : null;

                $product = Product::create([
                    'name' => $name,
                    'title' => $faker->boolean(30) ? $name : null,
                    'sku' => sprintf('SKU-%05d-%d', $globalIndex, $category->getKey()),
                    'brand' => $brand,
                    'country' => $faker->randomElement($countries),
                    'price_amount' => $price,
                    'discount_price' => $discount,
                    'currency' => 'RUB',
                    'in_stock' => $inStock,
                    'qty' => $qty,
                    'popularity' => $faker->numberBetween(0, 10000),
                    'is_active' => $faker->boolean(95),
                    'is_in_yml_feed' => $faker->boolean(90),
                    'warranty' => $faker->randomElement($warranties),
                    'with_dns' => $faker->boolean(70),
                    'short' => $faker->sentence(10),
                    'description' => '<p>'.$faker->paragraph(3).'</p>',
                    'extra_description' => $faker->boolean(40) ? '<p>'.$faker->paragraph(2).'</p>' : null,
                    'specs' => $faker->boolean(50) ? $faker->paragraph(2) : null,
                    'promo_info' => $faker->boolean(35) ? $faker->randomElement($promoLines) : null,
                    'image' => $this->randomPic(),
                    'thumb' => $this->randomPic(),
                    'gallery' => $this->randomGallery(3),
                    'meta_title' => $name,
                    'meta_description' => $faker->sentence(12),
                ]);

                $product->categories()->attach($category->getKey(), ['is_primary' => true]);

                $globalIndex++;
            }
        }
    }

    private function resetProducts(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::table('product_attribute_option')->truncate();
        DB::table('product_attribute_values')->truncate();
        DB::table('product_categories')->truncate();
        DB::table('products')->truncate();

        Schema::enableForeignKeyConstraints();
    }

    private function discountFrom(int $price, \Faker\Generator $faker): int
    {
        $discount = (int) round($price * $faker->randomFloat(2, 0.6, 0.95));

        return $discount >= $price ? max(1, $price - 1) : $discount;
    }

    private function randomPic(): ?string
    {
        $pool = $this->picPool();
        if ($pool === []) {
            if (! $this->picsWarned) {
                $this->command?->warn('Папка storage/app/public/pics пуста — изображения товаров будут пустыми.');
                $this->picsWarned = true;
            }

            return null;
        }

        return $pool[array_rand($pool)];
    }

    private function randomGallery(int $count): array
    {
        $pool = $this->picPool();
        if ($pool === []) {
            return [];
        }

        if ($count <= 0) {
            return [];
        }

        $poolCount = count($pool);
        if ($count >= $poolCount) {
            $gallery = [];
            for ($i = 0; $i < $count; $i++) {
                $gallery[] = $pool[array_rand($pool)];
            }

            return $gallery;
        }

        $keys = array_rand($pool, $count);
        $keys = is_array($keys) ? $keys : [$keys];

        return array_map(static fn (int $key) => $pool[$key], $keys);
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
