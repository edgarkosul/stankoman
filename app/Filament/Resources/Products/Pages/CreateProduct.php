<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Forms\Components\Repeater;
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
            'name' => $source->name.' (копия)',
            'slug' => $source->slug.'-copy',
            'meta_title' => $source->meta_title,
            'meta_description' => $source->meta_description,
            'price_amount' => $source->price_amount,
            'discount_price' => $source->discount_price,
            'with_dns' => $source->with_dns,
            'sku' => $source->sku,
            'brand' => $source->brand,
            'country' => $source->country,
            'warranty' => $source->warranty?->value,
            'in_stock' => $source->in_stock,
            'is_active' => $source->is_active,
            'is_in_yml_feed' => $source->is_in_yml_feed,
            'popularity' => $source->popularity,

            'wholesale_price' => $source->wholesale_price,
            'wholesale_currency' => $source->wholesale_currency,
            'auto_update_exchange_rate' => $source->auto_update_exchange_rate,
            'exchange_rate' => $source->exchange_rate,
            'wholesale_price_rub' => $source->wholesale_price_rub,
            'markup_multiplier' => $source->markup_multiplier,
            'margin_amount_rub' => $source->margin_amount_rub,

            'description' => $source->description,
            'instructions' => filled($source->instructions) ? $source->instructions : $source->extra_description,
            'video' => $source->video,

            'image' => $source->image,
            'gallery' => $source->gallery,
            'promo_info' => $source->promo_info,
            'short' => $source->short,

            // belongsToMany categories
            'categories' => $source->categories->pluck('id')->all(),
        ];

        $this->form->fill($data);
        $this->fillSpecsRepeater($source->specs ?? []);
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

        $target->forceFill([
            'title' => $source->title,
            'currency' => $source->currency,
            'qty' => $source->qty,
            'short' => $source->short,
            'extra_description' => $source->extra_description,
            'image' => $source->image,
            'gallery' => $source->gallery,
            'thumb' => $source->thumb,
        ])->saveQuietly();
    }

    /**
     * @param  array<int, array{name?: mixed, value?: mixed, source?: mixed}>  $specs
     */
    private function fillSpecsRepeater(array $specs): void
    {
        $component = $this->form->getComponentByStatePath('specs', withHidden: true);

        if (! $component instanceof Repeater) {
            return;
        }

        $items = [];

        foreach (array_values($specs) as $index => $item) {
            $items[$component->generateUuid() ?? $index] = is_array($item) ? $item : [];
        }

        $component->state($items);
    }
}
