<?php

namespace App\Support\Mail;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderSubmittedCustomerMail;
use App\Mail\OrderSubmittedManagerMail;
use App\Mail\WelcomeNoPassword;
use App\Mail\WelcomeSetPassword;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class MailPreviewFactory
{
    /**
     * @return list<array{key:string,group:string,label:string,expectedText:string}>
     */
    public function catalog(): array
    {
        return [
            [
                'key' => 'order-submitted-customer',
                'group' => 'Заказы',
                'label' => 'Заказ клиенту',
                'expectedText' => 'Спасибо за заказ',
            ],
            [
                'key' => 'order-submitted-manager',
                'group' => 'Заказы',
                'label' => 'Заказ менеджеру',
                'expectedText' => 'Новый заказ',
            ],
            [
                'key' => 'welcome-set-password',
                'group' => 'Welcome и Auth',
                'label' => 'Welcome: set password',
                'expectedText' => 'Установить пароль',
            ],
            [
                'key' => 'welcome-no-password',
                'group' => 'Welcome и Auth',
                'label' => 'Welcome: no password',
                'expectedText' => 'Установить пароль',
            ],
            [
                'key' => 'auth-verify-email',
                'group' => 'Welcome и Auth',
                'label' => 'Подтверждение e-mail',
                'expectedText' => 'Подтвердите e-mail',
            ],
            [
                'key' => 'auth-reset-password',
                'group' => 'Welcome и Auth',
                'label' => 'Сброс пароля',
                'expectedText' => 'Сброс пароля',
            ],
        ];
    }

    /**
     * @return array<string, list<array{key:string,group:string,label:string,expectedText:string}>>
     */
    public function groupedCatalog(): array
    {
        return collect($this->catalog())
            ->groupBy('group')
            ->map(fn ($items) => $items->values()->all())
            ->all();
    }

    public function render(string $key): string
    {
        $preview = $this->build($key);

        return $preview instanceof Mailable
            ? $preview->render()
            : (string) $preview->render();
    }

    public function build(string $key): Mailable|MailMessage
    {
        return match ($key) {
            'order-submitted-customer' => new OrderSubmittedCustomerMail($this->sampleOrder()),
            'order-submitted-manager' => new OrderSubmittedManagerMail($this->sampleOrder()),
            'welcome-set-password' => $this->welcomeSetPasswordPreview(),
            'welcome-no-password' => new WelcomeNoPassword($this->customerUser()),
            'auth-verify-email' => (new VerifyEmailNotification)->toMail($this->unverifiedUser()),
            'auth-reset-password' => (new ResetPasswordNotification('preview-reset-token'))->toMail($this->customerUser()),
            default => throw new InvalidArgumentException("Unknown mail preview [{$key}]"),
        };
    }

    private function customerUser(): User
    {
        return $this->existing(new User, [
            'id' => 101,
            'name' => 'Иван Петров',
            'email' => 'ivan.petrov@example.test',
            'phone' => '+79990000000',
            'email_verified_at' => Carbon::parse('2026-03-20 10:30:00'),
        ]);
    }

    private function unverifiedUser(): User
    {
        return $this->existing(new User, [
            'id' => 102,
            'name' => 'Мария Соколова',
            'email' => 'maria.sokolova@example.test',
            'phone' => '+79992223344',
            'email_verified_at' => null,
        ]);
    }

    private function sampleOrder(): Order
    {
        $order = $this->existing(new Order, [
            'id' => 501,
            'order_number' => '27-03-26/07',
            'public_hash' => 'preview-order-public-hash',
            'customer_name' => 'Иван Петров',
            'customer_email' => 'ivan.petrov@example.test',
            'customer_phone' => '+79990000000',
            'is_company' => true,
            'company_name' => 'ООО "Инструмент Сервис"',
            'inn' => '2311000012',
            'kpp' => '231101001',
            'shipping_country' => 'Россия',
            'shipping_region' => 'Краснодарский край',
            'shipping_city' => 'Краснодар',
            'shipping_street' => 'ул. Северная',
            'shipping_house' => '15',
            'shipping_postcode' => '350000',
            'shipping_comment' => 'Позвоните за час до доставки.',
            'pickup_point_id' => 'PVZ-221',
            'shipping_method' => 'delivery',
            'payment_method' => 'bank_transfer',
            'status' => OrderStatus::Submitted->value,
            'payment_status' => PaymentStatus::Awaiting->value,
            'items_subtotal' => '249900.00',
            'discount_total' => '10000.00',
            'shipping_total' => '0.00',
            'grand_total' => '239900.00',
            'currency' => 'RUB',
            'submitted_at' => Carbon::parse('2026-03-27 12:45:00'),
        ]);

        $order->setRelation('items', collect([
            $this->existing(new OrderItem, [
                'id' => 7001,
                'order_id' => $order->id,
                'sku' => 'IT-4500',
                'name' => 'Станок ленточнопильный IT-4500',
                'quantity' => 1,
                'price_amount' => '199900.00',
                'total_amount' => '199900.00',
            ]),
            $this->existing(new OrderItem, [
                'id' => 7002,
                'order_id' => $order->id,
                'sku' => 'IT-ROLLER-2M',
                'name' => 'Роликовый стол 2 м',
                'quantity' => 1,
                'price_amount' => '50000.00',
                'total_amount' => '50000.00',
            ]),
        ]));

        return $order;
    }

    private function welcomeSetPasswordPreview(): WelcomeSetPassword
    {
        $user = $this->unverifiedUser();

        return new WelcomeSetPassword($user, $this->passwordResetUrl($user));
    }

    private function passwordResetUrl(User $user): string
    {
        return route('password.reset', [
            'token' => 'preview-reset-token',
            'email' => $user->email,
        ]);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function existing(Model $model, array $attributes): Model
    {
        $model->forceFill($attributes);
        $model->exists = true;
        $model->wasRecentlyCreated = false;
        $model->syncOriginal();

        return $model;
    }
}
