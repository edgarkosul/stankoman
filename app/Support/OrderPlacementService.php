<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Orders\OrderSubmitted;
use App\Mail\WelcomeNoPassword;
use App\Mail\WelcomeVerifyAndSetPassword;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderPlacementService
{
    /**
     * @param  array<int, array{product_id:int, quantity:int, meta?:array<string, mixed>|null}>  $items
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $delivery
     * @param  array<string, mixed>  $review
     * @param  array{guest_only?:bool}  $options
     */
    public function submit(
        array $items,
        array $contact,
        array $delivery,
        array $review = [],
        array $options = [],
    ): Order {
        $order = DB::transaction(
            fn (): Order => $this->place($items, $contact, $delivery, $review, $options)
        );

        event(new OrderSubmitted($order));

        return $order;
    }

    /**
     * @param  array<int, array{product_id:int, quantity:int, meta?:array<string, mixed>|null}>  $items
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $delivery
     * @param  array<string, mixed>  $review
     * @param  array{guest_only?:bool}  $options
     */
    public function place(
        array $items,
        array $contact,
        array $delivery,
        array $review = [],
        array $options = [],
    ): Order {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Невозможно оформить заказ без товаров.',
            ]);
        }

        $guestOnly = (bool) ($options['guest_only'] ?? false);

        [$userId, $applyDiscounts] = $this->resolveOrderUser($contact, $guestOnly);
        $canUpdateShippingProfile = ! $guestOnly
            && $userId !== null
            && (Auth::check() || (bool) ($contact['create_account'] ?? false));

        if ($canUpdateShippingProfile) {
            $this->syncUserDeliveryProfile((int) $userId, $delivery);
            $this->syncUserCompanyProfile((int) $userId, $contact);
        }

        $itemsSubtotal = 0.0;
        $discountTotal = 0.0;
        $preparedItems = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = max(0, (int) ($item['quantity'] ?? 0));

            if ($productId <= 0 || $quantity === 0) {
                continue;
            }

            $product = Product::query()->find($productId);

            if (! $product instanceof Product) {
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

            $effectivePrice = $hasDiscount ? $discountPrice : $basePrice;
            $itemsSubtotal += $basePrice * $quantity;

            if ($hasDiscount) {
                $discountTotal += ($basePrice - $effectivePrice) * $quantity;
            }

            $meta = $item['meta'] ?? null;
            $meta = is_array($meta) && $meta !== [] ? $meta : null;

            $preparedItems[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity' => $quantity,
                'price_amount' => $effectivePrice,
                'thumbnail_url' => $product->image,
                'meta' => $meta,
            ];
        }

        if ($preparedItems === []) {
            throw ValidationException::withMessages([
                'items' => 'В заказе нет доступных товаров для оформления.',
            ]);
        }

        $shippingTotal = 0.0;

        $order = new Order;
        $order->fill([
            'user_id' => $userId,
            'status' => OrderStatus::Submitted->value,
            'payment_status' => PaymentStatus::Awaiting->value,
            'is_company' => (bool) ($contact['is_company'] ?? false),
            'customer_name' => (string) ($contact['customer_name'] ?? ''),
            'customer_email' => filled($contact['customer_email'] ?? null) ? (string) $contact['customer_email'] : null,
            'customer_phone' => (string) ($contact['customer_phone'] ?? ''),
            'company_name' => filled($contact['company_name'] ?? null) ? (string) $contact['company_name'] : null,
            'inn' => filled($contact['inn'] ?? null) ? (string) $contact['inn'] : null,
            'kpp' => filled($contact['kpp'] ?? null) ? (string) $contact['kpp'] : null,
            'shipping_method' => filled($delivery['shipping_method'] ?? null) ? (string) $delivery['shipping_method'] : 'delivery',
            'shipping_country' => filled($delivery['shipping_country'] ?? null) ? (string) $delivery['shipping_country'] : null,
            'shipping_region' => filled($delivery['shipping_region'] ?? null) ? (string) $delivery['shipping_region'] : null,
            'shipping_city' => filled($delivery['shipping_city'] ?? null) ? (string) $delivery['shipping_city'] : null,
            'shipping_street' => filled($delivery['shipping_street'] ?? null) ? (string) $delivery['shipping_street'] : null,
            'shipping_house' => filled($delivery['shipping_house'] ?? null) ? (string) $delivery['shipping_house'] : null,
            'shipping_postcode' => filled($delivery['shipping_postcode'] ?? null) ? (string) $delivery['shipping_postcode'] : null,
            'pickup_point_id' => filled($delivery['pickup_point_id'] ?? null) ? (string) $delivery['pickup_point_id'] : null,
            'shipping_comment' => filled($delivery['shipping_comment'] ?? null) ? (string) $delivery['shipping_comment'] : null,
            'payment_method' => filled($review['payment_method'] ?? null) ? (string) $review['payment_method'] : null,
            'items_subtotal' => round($itemsSubtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'shipping_total' => round($shippingTotal, 2),
            'grand_total' => round($itemsSubtotal - $discountTotal + $shippingTotal, 2),
            'submitted_at' => now(),
        ]);
        $order->save();

        foreach ($preparedItems as $item) {
            $order->items()->create($item);
        }

        return $order->fresh(['items']);
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array{0: int|null, 1: bool}
     */
    private function resolveOrderUser(array $contact, bool $guestOnly = false): array
    {
        if ($guestOnly) {
            return [null, false];
        }

        $createAccount = (bool) ($contact['create_account'] ?? false);
        $email = trim((string) ($contact['customer_email'] ?? ''));
        $phone = trim((string) ($contact['customer_phone'] ?? ''));
        $name = trim((string) ($contact['customer_name'] ?? ''));

        $applyDiscounts = Auth::check() || $createAccount;

        if (Auth::check()) {
            $user = Auth::user();

            if ($user instanceof User) {
                $this->syncUserContact($user, $name, $phone);

                return [$user->id, $applyDiscounts];
            }
        }

        if ($email === '') {
            return [null, $applyDiscounts];
        }

        $verifiedUser = User::query()
            ->where('email', $email)
            ->whereNotNull('email_verified_at')
            ->first();

        if ($verifiedUser instanceof User) {
            if ($createAccount) {
                $this->syncUserContact($verifiedUser, $name, $phone);
            }

            return [$verifiedUser->id, $applyDiscounts];
        }

        if (! $createAccount) {
            return [null, $applyDiscounts];
        }

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser instanceof User) {
            $this->syncUserContact($existingUser, $name, $phone);

            DB::afterCommit(function () use ($existingUser): void {
                if ($existingUser instanceof MustVerifyEmail && ! $existingUser->hasVerifiedEmail()) {
                    $existingUser->sendEmailVerificationNotification();
                }

                Mail::to($existingUser->email)->queue(new WelcomeNoPassword($existingUser));
            });

            return [$existingUser->id, $applyDiscounts];
        }

        $user = User::query()->create([
            'name' => $name !== '' ? $name : 'Пользователь',
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'password' => Hash::make(Str::password(16)),
        ]);

        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $token = Password::broker()->createToken($user);
        $resetUrl = route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]);

        DB::afterCommit(function () use ($user, $verifyUrl, $resetUrl): void {
            Mail::to($user->email)->queue(
                new WelcomeVerifyAndSetPassword($user, $verifyUrl, $resetUrl)
            );
        });

        return [$user->id, $applyDiscounts];
    }

    private function syncUserContact(User $user, string $name, string $phone): void
    {
        $updates = [];

        if ($name !== '' && $user->name !== $name) {
            $updates['name'] = $name;
        }

        if ($phone !== '' && $user->phone !== $phone) {
            $updates['phone'] = $phone;
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function syncUserDeliveryProfile(int $userId, array $delivery): void
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            return;
        }

        $updates = [];

        foreach ([
            'shipping_country',
            'shipping_region',
            'shipping_city',
            'shipping_street',
            'shipping_house',
            'shipping_postcode',
        ] as $key) {
            $value = trim((string) ($delivery[$key] ?? ''));

            if ($value !== '') {
                $updates[$key] = $value;
            }
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function syncUserCompanyProfile(int $userId, array $contact): void
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            return;
        }

        $isCompany = (bool) ($contact['is_company'] ?? false);
        $companyName = trim((string) ($contact['company_name'] ?? ''));
        $inn = preg_replace('/\D+/', '', (string) ($contact['inn'] ?? '')) ?? '';
        $kpp = preg_replace('/\D+/', '', (string) ($contact['kpp'] ?? '')) ?? '';

        $updates = [];

        if ((bool) ($user->is_company ?? false) !== $isCompany) {
            $updates['is_company'] = $isCompany;
        }

        if (! $isCompany) {
            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }

            return;
        }

        if ($companyName !== '' && $user->company_name !== $companyName) {
            $updates['company_name'] = $companyName;
        }

        if ($inn !== '' && $user->inn !== $inn) {
            $updates['inn'] = $inn;
        }

        if (strlen($inn) === 12) {
            if ($user->kpp !== null) {
                $updates['kpp'] = null;
            }
        } elseif ($kpp !== '' && $user->kpp !== $kpp) {
            $updates['kpp'] = $kpp;
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }
}
