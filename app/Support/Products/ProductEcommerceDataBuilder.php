<?php

namespace App\Support\Products;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

class ProductEcommerceDataBuilder
{
    public function detailPayload(Product $product): array
    {
        return [
            'currencyCode' => 'RUB',
            'detail' => [
                'products' => [$this->productLineItem($product)],
            ],
        ];
    }

    public function addToCartPayload(Product $product, int $quantity = 1): array
    {
        return [
            'currencyCode' => 'RUB',
            'add' => [
                'products' => [$this->productLineItem($product, quantity: $quantity)],
            ],
        ];
    }

    public function purchasePayload(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'currencyCode' => 'RUB',
            'purchase' => [
                'actionField' => [
                    'id' => (string) $order->order_number,
                    'revenue' => (float) $order->grand_total,
                ],
                'products' => $order->items
                    ->map(fn (OrderItem $item): array => $this->orderItemLineItem($item))
                    ->all(),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $images
     */
    public function productSchema(Product $product, array $images, ?string $description, string $url): array
    {
        $item = $this->productLineItem($product);
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $item['name'],
            'description' => filled($description) ? $description : null,
            'image' => $images,
            'sku' => $this->productAnalyticsId($product),
            'brand' => filled($item['brand'] ?? null)
                ? [
                    '@type' => 'Brand',
                    'name' => $item['brand'],
                ]
                : null,
            'category' => filled($item['category'] ?? null) ? $item['category'] : null,
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'RUB',
                'price' => (string) $item['price'],
                'availability' => $product->in_stock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'url' => $url,
            ],
        ];

        return array_filter($schema, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{analytics_id:string,brand:?string,category_path:?string}
     */
    public function productSnapshotMeta(Product $product): array
    {
        return [
            'analytics_id' => $this->productAnalyticsId($product),
            'brand' => filled($product->brand) ? (string) $product->brand : null,
            'category_path' => $this->categoryPath($product),
        ];
    }

    /**
     * @return array{id:string,name:string,price:int|float,brand?:string,category?:string,quantity:int}
     */
    public function productLineItem(Product $product, int $quantity = 1): array
    {
        $item = [
            'id' => $this->productAnalyticsId($product),
            'name' => (string) $product->name,
            'price' => (int) $product->price_final,
            'quantity' => max(1, $quantity),
        ];

        if (filled($product->brand)) {
            $item['brand'] = (string) $product->brand;
        }

        $categoryPath = $this->categoryPath($product);

        if (filled($categoryPath)) {
            $item['category'] = $categoryPath;
        }

        return $item;
    }

    /**
     * @return array{id:string,name:string,price:int|float,brand?:string,category?:string,quantity:int}
     */
    public function orderItemLineItem(OrderItem $item): array
    {
        /** @var array<string, mixed> $meta */
        $meta = is_array($item->meta) ? $item->meta : [];

        $analyticsId = trim((string) ($meta['analytics_id'] ?? $item->sku ?? $item->product_id));

        $lineItem = [
            'id' => $analyticsId !== '' ? $analyticsId : (string) $item->getKey(),
            'name' => (string) $item->name,
            'price' => (float) $item->price_amount,
            'quantity' => max(1, (int) $item->quantity),
        ];

        $brand = trim((string) ($meta['brand'] ?? ''));
        if ($brand !== '') {
            $lineItem['brand'] = $brand;
        }

        $categoryPath = trim((string) ($meta['category_path'] ?? ''));
        if ($categoryPath !== '') {
            $lineItem['category'] = $categoryPath;
        }

        return $lineItem;
    }

    public function productAnalyticsId(Product $product): string
    {
        $sku = trim((string) ($product->sku ?? ''));

        return $sku !== '' ? $sku : (string) $product->getKey();
    }

    private function categoryPath(Product $product): ?string
    {
        $category = $product->primaryCategory();

        if ($category === null) {
            return null;
        }

        $path = $category->ancestorsAndSelf()
            ->pluck('name')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->implode(' / ');

        return $path !== '' ? $path : null;
    }
}
