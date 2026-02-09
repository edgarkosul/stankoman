<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductSeeder extends Seeder
{
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

        $warranties = [
            '6 мес.',
            '12 мес.',
            '24 мес.',
            '36 мес.',
        ];

        $total = 1000;
        $leafCount = $leafCategories->count();
        $perLeaf = intdiv($total, $leafCount);
        $remainder = $total % $leafCount;

        $globalIndex = 1;

        foreach ($leafCategories as $index => $category) {
            $count = $perLeaf + ($index < $remainder ? 1 : 0);

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

                $seed = sprintf('p%04d', $globalIndex);

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
                    'description' => '<p>' . $faker->paragraph(3) . '</p>',
                    'extra_description' => $faker->boolean(40) ? '<p>' . $faker->paragraph(2) . '</p>' : null,
                    'specs' => $faker->boolean(50) ? $faker->paragraph(2) : null,
                    'promo_info' => $faker->boolean(35) ? $faker->randomElement($promoLines) : null,
                    'image' => $this->productImage($seed, 800, 600),
                    'thumb' => $this->productImage($seed . '-thumb', 400, 300),
                    'gallery' => [
                        $this->productImage($seed . '-1', 800, 600),
                        $this->productImage($seed . '-2', 800, 600),
                        $this->productImage($seed . '-3', 800, 600),
                    ],
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

    private function productImage(string $seed, int $width, int $height): string
    {
        return "https://picsum.photos/seed/{$seed}/{$width}/{$height}";
    }

    private function discountFrom(int $price, \Faker\Generator $faker): int
    {
        $discount = (int) round($price * $faker->randomFloat(2, 0.6, 0.95));

        return $discount >= $price ? max(1, $price - 1) : $discount;
    }
}
