<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * ID товара-источника при копировании (?from=ID).
     *
     * Обязательно public, чтобы Livewire его гидрейтил между запросами.
     */
    public ?int $sourceProductId = null;

    /**
     * Хук: после первичного fill формы.
     * Здесь подменяем значения на данные исходного товара.
     */
    protected function afterFill(): void
    {
        // Инициализируем только если ещё не инициализировано
        if (! $this->sourceProductId) {
            $this->sourceProductId = request()->integer('from');
        }

        if (! $this->sourceProductId) {
            return;
        }

        /** @var Product|null $source */
        $source = Product::with(['categories', 'attributeOptions', 'attributeValues'])
            ->find($this->sourceProductId);

        if (! $source) {
            return;
        }

        $data = [
            'name'              => $source->name . ' (копия)',
            'slug'              => $source->slug . '-copy',
            'price_amount'      => $source->price_amount,
            'discount_price'    => $source->discount_price,
            'with_dns'          => $source->with_dns,
            'sku'               => $source->sku,
            'brand'             => $source->brand,
            'country'           => $source->country,
            'warranty'          => $source->warranty,
            'in_stock'          => $source->in_stock,
            'is_active'         => false,
            'popularity'        => $source->popularity,

            'description'       => $source->description,
            'extra_description' => $source->extra_description,

            'image'             => $source->image,
            'gallery'           => $source->gallery,
            'promo_info'        => $source->promo_info,
            'short'             => $source->short,

            // belongsToMany categories
            'categories'        => $source->categories->pluck('id')->all(),
        ];

        $this->form->fill($data);
    }

    /**
     * Хук: после создания записи и сохранения полей формы.
     * Здесь копируем связи и выставляем primary-категорию.
     */
    protected function afterCreate(): void
    {
        if (! $this->sourceProductId) {
            return;
        }

        /** @var Product|null $target */
        $target = $this->record;
        if (! $target) {
            return;
        }

        /** @var Product|null $source */
        $source = Product::with(['categories', 'attributeOptions', 'attributeValues'])
            ->find($this->sourceProductId);

        if (! $source) {
            return;
        }

        /*
         * 1) Копируем attributeOptions (product_attribute_option)
         */
        $pivotData = [];

        foreach ($source->attributeOptions as $option) {
            $pivotData[$option->getKey()] = [
                'attribute_id' => $option->pivot->attribute_id,
            ];
        }

        if ($pivotData) {
            $target->attributeOptions()->syncWithoutDetaching($pivotData);
        }

        /*
         * 2) Копируем attributeValues (product_attribute_values)
         */
        foreach ($source->attributeValues as $value) {
            $clone = $value->replicate(['product_id']);
            $clone->product_id = $target->getKey();
            $clone->save();
        }

        /*
         * 3) Выставляем primary-категорию, если у исходного товара она была
         */
        $primary = $source->primaryCategory();

        if ($primary) {
            // Категории уже сохранены из формы.
            // Если пользователь в форме убрал эту категорию — update просто не заденет ни одной строки.
            $target->setPrimaryCategory($primary->id);
        }
    }
}
