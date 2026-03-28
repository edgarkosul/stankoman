<?php

namespace App\Support\Mail;

use App\Models\Order;
use BackedEnum;
use Illuminate\Support\Carbon;

class OrderMailViewData
{
    public function __construct(public Order $order) {}

    public function shopName(): string
    {
        return (string) config('settings.general.shop_name', config('app.name'));
    }

    public function submittedAt(): string
    {
        $submittedAt = $this->order->submitted_at;

        if ($submittedAt instanceof Carbon) {
            return $submittedAt->format('d.m.Y H:i');
        }

        if (filled($submittedAt)) {
            return Carbon::parse($submittedAt)->format('d.m.Y H:i');
        }

        return '—';
    }

    public function paymentMethodLabel(): ?string
    {
        return $this->translatedLabel('order.payment_method', $this->stringValue($this->order->payment_method));
    }

    public function shippingMethodLabel(): ?string
    {
        return $this->translatedLabel('order.shipping_method', $this->stringValue($this->order->shipping_method));
    }

    public function deliveryMethodLabel(): string
    {
        return $this->shippingMethodLabel() ?? 'По согласованию';
    }

    public function shouldShowManagerDeliverySection(): bool
    {
        return $this->shippingMethodLabel() !== null || $this->managerDeliveryDetailsPresent();
    }

    /**
     * @return list<array{name:string, quantity:string, price:string, total:string, sku?:string}>
     */
    public function items(bool $withSku = false): array
    {
        return $this->order->items->map(function ($item) use ($withSku): array {
            $quantity = (int) $item->quantity;
            $priceAmount = (float) $item->price_amount;
            $totalAmount = (float) ($item->total_amount ?? ($priceAmount * $quantity));

            $row = [
                'name' => $this->tableCell((string) ($item->name ?: 'Товар')),
                'quantity' => (string) $quantity,
                'price' => $this->tableCell($this->money($priceAmount)),
                'total' => $this->tableCell($this->money($totalAmount)),
            ];

            if ($withSku) {
                $row['sku'] = $this->tableCell((string) ($item->sku ?: '—'));
            }

            return $row;
        })->all();
    }

    /**
     * @return list<array{label:string, value:string, strong:bool}>
     */
    public function totals(string $grandTotalLabel = 'Итого к оплате'): array
    {
        $rows = [
            [
                'label' => 'Товары',
                'value' => $this->tableCell($this->money((float) $this->order->items_subtotal)),
                'strong' => false,
            ],
        ];

        if ((float) $this->order->discount_total > 0) {
            $rows[] = [
                'label' => 'Скидка',
                'value' => $this->tableCell('− '.$this->money((float) $this->order->discount_total)),
                'strong' => false,
            ];
        }

        if ((float) $this->order->shipping_total > 0) {
            $rows[] = [
                'label' => 'Доставка',
                'value' => $this->tableCell($this->money((float) $this->order->shipping_total)),
                'strong' => false,
            ];
        }

        $rows[] = [
            'label' => $grandTotalLabel,
            'value' => $this->tableCell($this->money((float) $this->order->grand_total)),
            'strong' => true,
        ];

        return $rows;
    }

    /**
     * @return list<array{label:string, value:string, url?:string}>
     */
    public function customerContactRows(): array
    {
        $rows = [];

        $this->pushRow($rows, 'Имя', $this->stringValue($this->order->customer_name));

        if ($phone = $this->stringValue($this->order->customer_phone)) {
            $this->pushRow($rows, 'Телефон', $phone, $this->phoneUrl($phone));
        }

        if ($email = $this->stringValue($this->order->customer_email)) {
            $this->pushRow($rows, 'Email', $email, 'mailto:'.$email);
        }

        $this->pushRow($rows, 'Компания', $this->companyDetails());

        return $rows;
    }

    /**
     * @return list<array{label:string, value:string, url?:string}>
     */
    public function customerDeliveryRows(): array
    {
        $rows = [];

        $this->pushRow($rows, 'Способ', $this->deliveryMethodLabel());
        $this->pushRow($rows, 'Адрес', $this->deliveryAddress());
        $this->pushRow($rows, 'Комментарий', $this->stringValue($this->order->shipping_comment));

        return $rows;
    }

    /**
     * @return list<array{label:string, value:string, url?:string}>
     */
    public function managerMetaRows(): array
    {
        $rows = [];

        $this->pushRow($rows, 'Дата', $this->submittedAt());
        $this->pushRow($rows, 'Статус заказа', $this->stringValue($this->order->status));
        $this->pushRow($rows, 'Статус оплаты', $this->stringValue($this->order->payment_status));
        $this->pushRow($rows, 'Способ оплаты', $this->paymentMethodLabel());
        $this->pushRow($rows, 'ID заказа', $this->stringValue($this->order->id));

        return $rows;
    }

    /**
     * @return list<array{label:string, value:string, url?:string}>
     */
    public function managerDeliveryRows(): array
    {
        if (! $this->shouldShowManagerDeliverySection()) {
            return [];
        }

        $rows = [];

        $this->pushRow($rows, 'Способ', $this->deliveryMethodLabel());
        $this->pushRow($rows, 'Адрес', $this->deliveryAddress());
        $this->pushRow($rows, 'ПВЗ', $this->stringValue($this->order->pickup_point_id));
        $this->pushRow($rows, 'Комментарий', $this->stringValue($this->order->shipping_comment));

        return $rows;
    }

    private function translatedLabel(string $prefix, ?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $translationKey = "{$prefix}.{$value}";
        $translated = __($translationKey);

        return $translated === $translationKey ? $value : $translated;
    }

    private function deliveryAddress(): ?string
    {
        $address = trim(collect([
            $this->order->shipping_postcode,
            $this->order->shipping_country,
            $this->order->shipping_region,
            $this->order->shipping_city,
            $this->order->shipping_street,
            $this->order->shipping_house,
        ])->map(fn ($value) => $this->stringValue($value))
            ->filter()
            ->implode(', '));

        return $address !== '' ? $address : null;
    }

    private function companyDetails(): ?string
    {
        $companyName = $this->stringValue($this->order->company_name);

        if (! $this->order->is_company || ! filled($companyName)) {
            return null;
        }

        $suffix = [];

        if ($inn = $this->stringValue($this->order->inn)) {
            $suffix[] = "ИНН {$inn}";
        }

        if ($kpp = $this->stringValue($this->order->kpp)) {
            $suffix[] = "КПП {$kpp}";
        }

        if ($suffix === []) {
            return $companyName;
        }

        return $companyName.' ('.implode(', ', $suffix).')';
    }

    private function managerDeliveryDetailsPresent(): bool
    {
        return collect([
            $this->deliveryAddress(),
            $this->order->pickup_point_id,
            $this->order->shipping_comment,
        ])->map(fn ($value) => $this->stringValue($value))
            ->filter()
            ->isNotEmpty();
    }

    private function phoneUrl(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits !== '' ? 'tel:'.$digits : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function tableCell(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $normalized = str_replace('|', '\|', $normalized);

        return $normalized !== '' ? $normalized : '—';
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' '.$this->currency();
    }

    private function currency(): string
    {
        return match (mb_strtoupper((string) $this->order->currency)) {
            'RUB', 'RUR' => '₽',
            default => (string) ($this->order->currency ?: '₽'),
        };
    }

    /**
     * @param  list<array{label:string, value:string, url?:string}>  $rows
     */
    private function pushRow(array &$rows, string $label, ?string $value, ?string $url = null): void
    {
        if (! filled($value)) {
            return;
        }

        $row = [
            'label' => $label,
            'value' => $value,
        ];

        if ($url !== null) {
            $row['url'] = $url;
        }

        $rows[] = $row;
    }
}
