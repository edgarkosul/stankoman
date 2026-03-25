<?php

namespace App\Livewire\Pages\Product;

use App\Models\Product;
use App\Models\User;
use App\Rules\ValidPhone;
use App\Support\OrderPlacementService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class OneClickOrder extends Component
{
    public int $productId;

    public bool $isOpen = false;

    public bool $submitted = false;

    public int $quantity = 1;

    public string $customerName = '';

    public string $customerPhone = '';

    public string $customerEmail = '';

    public string $shippingCountry = 'Россия';

    public string $shippingRegion = '';

    public string $shippingComment = '';

    public bool $acceptTerms = false;

    public ?string $submittedOrderNumber = null;

    /**
     * @var array{id:int,name:string,brand:?string,price_formatted:string}
     */
    public array $product = [];

    public function mount(int $productId): void
    {
        $this->productId = $productId;
        $this->product = $this->resolveProductSnapshot();
        $this->resetFormState();
    }

    #[On('one-click-order:open')]
    public function openModal(int $productId, int $quantity = 1): void
    {
        if ($productId !== $this->productId) {
            return;
        }

        $this->resetErrorBag();
        $this->resetValidation();
        $this->resetFormState();
        $this->quantity = max(1, $quantity);
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetErrorBag();
        $this->resetValidation();
        $this->resetFormState();
    }

    public function submit(OrderPlacementService $orders): void
    {
        $this->normalizePayload();

        $this->validate(
            $this->rules(),
            messages: $this->messages(),
            attributes: $this->validationAttributes(),
        );

        $order = $orders->submit(
            items: [[
                'product_id' => $this->productId,
                'quantity' => $this->quantity,
                'meta' => array_filter([
                    'brand' => $this->product['brand'] ?? null,
                ], fn (mixed $value): bool => filled($value)),
            ]],
            contact: [
                'is_company' => false,
                'customer_name' => $this->customerName,
                'customer_phone' => $this->customerPhone,
                'customer_email' => $this->customerEmail !== '' ? $this->customerEmail : null,
                'create_account' => false,
            ],
            delivery: [
                'shipping_method' => 'delivery',
                'shipping_country' => $this->shippingCountry,
                'shipping_region' => $this->shippingRegion,
                'shipping_comment' => $this->shippingComment !== '' ? $this->shippingComment : null,
            ],
            review: [],
            options: [
                'guest_only' => true,
            ],
        );

        $this->submitted = true;
        $this->submittedOrderNumber = $order->order_number;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'customerName' => ['required', 'string', 'min:2', 'max:100'],
            'customerPhone' => ['required', 'string', 'max:32', new ValidPhone],
            'customerEmail' => ['nullable', 'string', 'max:190', 'email:rfc'],
            'shippingCountry' => ['required', 'string', 'max:64'],
            'shippingRegion' => ['nullable', 'string', 'max:128'],
            'shippingComment' => ['nullable', 'string', 'max:1000'],
            'acceptTerms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'customerName.required' => 'Укажите имя.',
            'customerPhone.required' => 'Укажите телефон.',
            'customerEmail.email' => 'Укажите корректный email.',
            'shippingCountry.required' => 'Укажите страну.',
            'acceptTerms.accepted' => 'Необходимо принять условия соглашения.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'customerName' => 'имя',
            'customerPhone' => 'телефон',
            'customerEmail' => 'email',
            'shippingCountry' => 'страна',
            'shippingRegion' => 'регион',
            'shippingComment' => 'сообщение',
            'acceptTerms' => 'условия соглашения',
        ];
    }

    public function render(): View
    {
        return view('livewire.pages.product.one-click-order');
    }

    private function resetFormState(): void
    {
        $this->quantity = 1;
        $this->customerName = '';
        $this->customerPhone = '';
        $this->customerEmail = '';
        $this->shippingCountry = 'Россия';
        $this->shippingRegion = '';
        $this->shippingComment = '';
        $this->acceptTerms = false;
        $this->submitted = false;
        $this->submittedOrderNumber = null;

        $this->prefillContactFromAuthenticatedUser();
    }

    private function prefillContactFromAuthenticatedUser(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        if ($this->customerName === '') {
            $this->customerName = (string) $user->name;
        }

        if ($this->customerEmail === '' && filled($user->email)) {
            $this->customerEmail = (string) $user->email;
        }

        if ($this->customerPhone === '' && filled($user->phone)) {
            $this->customerPhone = (string) $user->phone;
        }

        if ($this->shippingCountry === 'Россия' && filled($user->shipping_country)) {
            $this->shippingCountry = (string) $user->shipping_country;
        }

        if ($this->shippingRegion === '' && filled($user->shipping_region)) {
            $this->shippingRegion = (string) $user->shipping_region;
        }
    }

    private function normalizePayload(): void
    {
        $this->customerName = $this->sanitize($this->customerName);
        $this->customerEmail = $this->normalizeEmail($this->customerEmail);
        $this->shippingCountry = $this->sanitize($this->shippingCountry);
        $this->shippingRegion = $this->sanitize($this->shippingRegion);
        $this->shippingComment = $this->sanitize($this->shippingComment);
        $this->quantity = max(1, (int) $this->quantity);

        $normalizedPhone = ValidPhone::normalize($this->customerPhone);

        if ($normalizedPhone !== null) {
            $this->customerPhone = $normalizedPhone;
        }
    }

    /**
     * @return array{id:int,name:string,brand:?string,price_formatted:string}
     */
    private function resolveProductSnapshot(): array
    {
        $product = Product::query()
            ->select(['id', 'name', 'brand', 'price_amount'])
            ->find($this->productId);

        if (! $product instanceof Product) {
            return [
                'id' => $this->productId,
                'name' => 'Товар',
                'brand' => null,
                'price_formatted' => price(0),
            ];
        }

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'brand' => filled($product->brand) ? (string) $product->brand : null,
            'price_formatted' => price((int) ($product->price_amount ?? 0)),
        ];
    }

    private function normalizeEmail(string $value): string
    {
        $normalized = trim(mb_strtolower($value));

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : trim($value);
    }

    private function sanitize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
