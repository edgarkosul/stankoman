<?php

namespace App\Livewire\Checkout;

use App\Models\User;
use App\Rules\ValidInn;
use App\Rules\ValidKpp;
use App\Rules\ValidPhone;
use App\Support\CartService;
use App\Support\CheckoutService;
use App\Support\CompanyLookupService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Wizard extends Component
{
    public int $currentStep = 1;

    public array $contact = [
        'is_company' => false,
        'customer_name' => '',
        'customer_phone' => '',
        'customer_email' => '',
        'create_account' => false,
        'company_name' => null,
        'inn' => null,
        'kpp' => null,
    ];

    public array $delivery = [
        'shipping_method' => 'delivery',
        'shipping_country' => null,
        'shipping_region' => null,
        'shipping_city' => null,
        'shipping_street' => null,
        'shipping_house' => null,
        'shipping_postcode' => null,
        'shipping_comment' => null,
        'pickup_point_id' => null,
    ];

    public array $review = [
        'payment_method' => 'cash',
        'accept_terms' => true,
    ];

    public array $totals = [
        'items_subtotal' => 0.0,
        'discount_total' => 0.0,
        'shipping_total' => 0.0,
        'grand_total' => 0.0,
        'currency' => 'RUB',
    ];

    public Collection $items;

    public function mount(CartService $cart): void
    {
        $this->items = collect();
        $this->prefillContactFromAuthenticatedUser();
        $this->refreshFromCart($cart);
    }

    public function updated(string $property, mixed $value): void
    {
        if ($property === 'contact.create_account') {
            $this->recalcTotals();

            return;
        }

        if ($property === 'contact.customer_email') {
            $this->contact['customer_email'] = $this->normalizeEmail((string) $value);

            return;
        }

        if ($property === 'contact.customer_phone') {
            $this->contact['customer_phone'] = trim((string) $value);

            return;
        }

        if ($property === 'contact.company_name') {
            $this->contact['company_name'] = $this->sanitize((string) $value);

            return;
        }

        if ($property === 'contact.kpp') {
            $this->contact['kpp'] = $this->digits((string) $value, 9) ?: null;

            return;
        }

        if ($property === 'contact.is_company') {
            $this->handleCompanyToggle((bool) $value);

            return;
        }

        if ($property === 'contact.inn') {
            $this->handleInnChanged((string) $value);
        }
    }

    public function next(): void
    {
        if ($this->currentStep === 1) {
            $this->validateContactStep();
            $this->currentStep = 2;

            return;
        }

        if ($this->currentStep === 2) {
            $this->validateDeliveryStep();
            $this->currentStep = 3;
        }
    }

    public function previous(): void
    {
        $this->currentStep = max(1, $this->currentStep - 1);
    }

    public function confirm(CheckoutService $checkout)
    {
        $this->validateAll();

        $order = $checkout->submit($this->contact, $this->delivery, $this->review);

        return redirect()->route('checkout.success', [
            'date' => $order->order_date->format('d-m-y'),
            'seq' => str_pad((string) $order->seq, 2, '0', STR_PAD_LEFT),
        ]);
    }

    protected function refreshFromCart(CartService $cart): void
    {
        $cartModel = $cart->getCart()->load('items.product');
        $this->items = $cartModel->items;

        $this->recalcTotals();
    }

    protected function validateContactStep(): void
    {
        $this->normalizeContactPayload();

        $this->validate($this->contactRules(), messages: $this->messages(), attributes: $this->validationAttributes());
    }

    protected function validateDeliveryStep(): void
    {
        $this->validate($this->deliveryRules(), messages: $this->messages(), attributes: $this->validationAttributes());
    }

    protected function validateAll(): void
    {
        $this->normalizeContactPayload();

        $this->validate(
            [
                ...$this->contactRules(),
                ...$this->deliveryRules(),
                ...$this->reviewRules(),
            ],
            messages: $this->messages(),
            attributes: $this->validationAttributes(),
        );
    }

    protected function contactRules(): array
    {
        $isCompany = (bool) ($this->contact['is_company'] ?? false);
        $innLength = strlen($this->digits((string) ($this->contact['inn'] ?? ''), 12));

        $kppRules = ['nullable', 'regex:/^\d{9}$/', new ValidKpp];

        if ($isCompany && $innLength === 10) {
            array_unshift($kppRules, 'required');
        }

        return [
            'contact.is_company' => ['boolean'],
            'contact.customer_name' => ['required', 'string', 'min:2', 'max:100'],
            'contact.customer_phone' => ['required', 'string', 'max:32', new ValidPhone],
            'contact.customer_email' => ['nullable', 'required_if:contact.create_account,true', 'string', 'max:190', 'email:rfc'],
            'contact.create_account' => ['boolean'],
            'contact.company_name' => ['nullable', 'required_if:contact.is_company,true', 'string', 'min:2', 'max:255'],
            'contact.inn' => ['nullable', 'required_if:contact.is_company,true', 'regex:/^\d{10}(\d{2})?$/', new ValidInn],
            'contact.kpp' => $kppRules,
        ];
    }

    protected function deliveryRules(): array
    {
        return [
            'delivery.shipping_method' => ['required', 'in:pickup,delivery'],
            'delivery.shipping_country' => ['nullable', 'string', 'max:64'],
            'delivery.shipping_region' => ['nullable', 'string', 'max:128'],
            'delivery.shipping_city' => ['nullable', 'required_if:delivery.shipping_method,delivery', 'string', 'min:2', 'max:128'],
            'delivery.shipping_street' => ['nullable', 'string', 'max:255'],
            'delivery.shipping_house' => ['nullable', 'string', 'max:32'],
            'delivery.shipping_postcode' => ['nullable', 'string', 'max:16'],
            'delivery.shipping_comment' => ['nullable', 'string', 'max:1000'],
            'delivery.pickup_point_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function reviewRules(): array
    {
        return [
            'review.payment_method' => ['required', 'in:cash,bank_transfer,credit'],
            'review.accept_terms' => ['accepted'],
        ];
    }

    protected function messages(): array
    {
        return [
            'contact.customer_name.required' => 'Укажите ФИО.',
            'contact.customer_phone.required' => 'Укажите телефон.',
            'contact.customer_email.required_if' => 'Укажите email для создания личного кабинета.',
            'contact.customer_email.email' => 'Укажите корректный email.',
            'contact.company_name.required_if' => 'Укажите название компании.',
            'contact.inn.required_if' => 'Укажите ИНН.',
            'contact.inn.regex' => 'ИНН должен содержать 10 или 12 цифр.',
            'contact.kpp.required' => 'Укажите КПП для организации.',
            'contact.kpp.regex' => 'КПП должен содержать 9 цифр.',
            'delivery.shipping_method.required' => 'Выберите способ доставки.',
            'delivery.shipping_city.required_if' => 'Укажите город доставки.',
            'review.payment_method.required' => 'Выберите способ оплаты.',
            'review.accept_terms.accepted' => 'Необходимо принять условия соглашения.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'contact.customer_name' => 'ФИО',
            'contact.customer_phone' => 'телефон',
            'contact.customer_email' => 'email',
            'contact.create_account' => 'создание личного кабинета',
            'contact.company_name' => 'название компании',
            'contact.inn' => 'ИНН',
            'contact.kpp' => 'КПП',
            'delivery.shipping_method' => 'способ доставки',
            'delivery.shipping_city' => 'город',
            'review.payment_method' => 'способ оплаты',
            'review.accept_terms' => 'условия соглашения',
        ];
    }

    protected function recalcTotals(): void
    {
        $itemsSubtotal = 0.0;
        $discountTotal = 0.0;
        $applyDiscounts = Auth::check() || (bool) ($this->contact['create_account'] ?? false);

        foreach ($this->items as $item) {
            $product = $item->product;
            $quantity = max(0, (int) $item->quantity);

            if ($quantity === 0 || $product === null) {
                continue;
            }

            $basePrice = (int) ($product->price_int ?? $product->price_amount ?? 0);

            if ($basePrice <= 0) {
                continue;
            }

            $discountPrice = $product->discount;
            $discountPrice = $discountPrice === null ? null : (int) $discountPrice;

            $hasDiscount = $applyDiscounts
                && $discountPrice !== null
                && $discountPrice > 0
                && $discountPrice < $basePrice;

            $itemsSubtotal += $basePrice * $quantity;

            if ($hasDiscount) {
                $discountTotal += ($basePrice - $discountPrice) * $quantity;
            }
        }

        $shippingTotal = (float) ($this->totals['shipping_total'] ?? 0.0);

        $this->totals['items_subtotal'] = round($itemsSubtotal, 2);
        $this->totals['discount_total'] = round($discountTotal, 2);
        $this->totals['shipping_total'] = round($shippingTotal, 2);
        $this->totals['grand_total'] = round($itemsSubtotal - $discountTotal + $shippingTotal, 2);
        $this->totals['currency'] = 'RUB';
    }

    public function render()
    {
        return view('livewire.checkout.wizard')
            ->layout('layouts.catalog', ['title' => 'Оформление заказа']);
    }

    private function prefillContactFromAuthenticatedUser(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        if (($this->contact['customer_name'] ?? '') === '') {
            $this->contact['customer_name'] = $user->name;
        }

        if (($this->contact['customer_email'] ?? '') === '') {
            $this->contact['customer_email'] = $user->email;
        }

        if (($this->contact['customer_phone'] ?? '') === '' && filled($user->phone)) {
            $this->contact['customer_phone'] = (string) $user->phone;
        }

        $this->contact['create_account'] = false;
    }

    private function normalizeContactPayload(): void
    {
        $this->contact['customer_name'] = $this->sanitize((string) ($this->contact['customer_name'] ?? ''));
        $this->contact['customer_email'] = $this->normalizeEmail((string) ($this->contact['customer_email'] ?? ''));

        $normalizedPhone = ValidPhone::normalize((string) ($this->contact['customer_phone'] ?? ''));

        if ($normalizedPhone !== null) {
            $this->contact['customer_phone'] = $normalizedPhone;
        }

        if (! (bool) ($this->contact['is_company'] ?? false)) {
            $this->contact['company_name'] = null;
            $this->contact['inn'] = null;
            $this->contact['kpp'] = null;

            return;
        }

        $this->contact['company_name'] = $this->sanitize((string) ($this->contact['company_name'] ?? '')) ?: null;
        $this->contact['inn'] = $this->digits((string) ($this->contact['inn'] ?? ''), 12) ?: null;

        if (strlen((string) $this->contact['inn']) === 10) {
            $this->contact['kpp'] = $this->digits((string) ($this->contact['kpp'] ?? ''), 9) ?: null;
        } else {
            $this->contact['kpp'] = null;
        }
    }

    private function handleCompanyToggle(bool $isCompany): void
    {
        if (! $isCompany) {
            $this->contact['company_name'] = null;
            $this->contact['inn'] = null;
            $this->contact['kpp'] = null;
            $this->resetErrorBag(['contact.company_name', 'contact.inn', 'contact.kpp']);

            return;
        }

        $this->contact['company_name'] = $this->sanitize((string) ($this->contact['company_name'] ?? '')) ?: null;
        $this->contact['inn'] = $this->digits((string) ($this->contact['inn'] ?? ''), 12) ?: null;
        $this->contact['kpp'] = $this->digits((string) ($this->contact['kpp'] ?? ''), 9) ?: null;

        if (strlen((string) $this->contact['inn']) === 12) {
            $this->contact['kpp'] = null;
        }
    }

    private function handleInnChanged(string $rawInn): void
    {
        $digits = $this->digits($rawInn, 12);
        $this->contact['inn'] = $digits ?: null;

        if ($digits === '') {
            $this->contact['kpp'] = null;
            $this->contact['company_name'] = null;
            $this->resetErrorBag(['contact.inn', 'contact.kpp', 'contact.company_name']);

            return;
        }

        if (strlen($digits) === 12) {
            $this->contact['kpp'] = null;
            $this->resetErrorBag(['contact.kpp']);
        }

        if (! (bool) ($this->contact['is_company'] ?? false)) {
            return;
        }

        if (! in_array(strlen($digits), [10, 12], true)) {
            $this->resetErrorBag(['contact.inn', 'contact.kpp']);

            return;
        }

        /** @var CompanyLookupService $lookup */
        $lookup = app(CompanyLookupService::class);
        $payload = $lookup->byInn($digits);

        if ($payload === []) {
            return;
        }

        if (blank($this->contact['company_name'] ?? null) && filled($payload['company_name'] ?? null)) {
            $this->contact['company_name'] = $this->sanitize((string) $payload['company_name']) ?: null;
        }

        if (strlen($digits) === 10 && filled($payload['kpp'] ?? null)) {
            $this->contact['kpp'] = $this->digits((string) $payload['kpp'], 9) ?: null;
        }
    }

    private function digits(string $value, int $max = 64): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($max <= 0) {
            return $digits;
        }

        return mb_substr($digits, 0, $max);
    }

    private function normalizeEmail(string $value): string
    {
        $normalized = preg_replace('/[\x{00A0}\x{2007}\x{202F}\x{200B}\x{2060}\s]+/u', '', trim($value)) ?? '';

        if (! str_contains($normalized, '@')) {
            return $normalized;
        }

        [$local, $domain] = explode('@', $normalized, 2);
        $domain = mb_strtolower(rtrim($domain, '.'));

        if (function_exists('idn_to_ascii')) {
            $asciiDomain = idn_to_ascii($domain);

            if ($asciiDomain !== false) {
                $domain = $asciiDomain;
            }
        }

        return "{$local}@{$domain}";
    }

    private function sanitize(string $value): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        $normalized = str_replace(['«', '»', '“', '”', '„', '‟'], '"', $normalized);

        return str_replace(['’', '‘', '‛'], "'", $normalized);
    }
}
