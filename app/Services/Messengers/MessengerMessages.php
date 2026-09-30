<?php

namespace App\Services\Messengers;

use App\Filament\Resources\CallbackRequests\CallbackRequestResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\Product;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Тексты уведомлений в MAX.
 *
 * Только plain text. Разметки в MAX нет, а если бы и была, её сломал бы
 * любой спецсимвол в имени или комментарии покупателя. Раскладка такая:
 * заголовок, пустая строка, строки «Метка: значение» (пустые поля
 * пропускаются) и ссылка в админку отдельной строкой. MAX делает ссылку
 * кликабельной сам, и с телефона сразу открывается карточка.
 *
 * Поводов два — заказ и заявка на звонок: это всё, что магазин принимает
 * с сайта. Вопрос из чата шлёт не этот класс, а джоба эскалации: у неё
 * свой текст с выжимкой разговора и свои правила о времени суток.
 */
final class MessengerMessages
{
    public static function order(Order $order): string
    {
        $items = $order->relationLoaded('items') ? $order->items->count() : null;
        $shipping = $order->shipping_method instanceof BackedEnum ? $order->shipping_method->label() : null;
        $payment = filled($order->payment_method) ? __('order.payment_method.'.$order->payment_method) : null;

        return self::compose('Новый заказ № '.$order->order_number, [
            'Сумма' => self::money($order->grand_total),
            'Позиций' => $items ?: null,
            'Покупатель' => $order->customer_name,
            'Компания' => $order->is_company ? trim($order->company_name.($order->inn ? ', ИНН '.$order->inn : '')) : null,
            'Телефон' => self::phone($order->customer_phone),
            'Почта' => $order->customer_email,
            'Доставка' => trim(implode(', ', array_filter([$shipping, $order->shipping_city]))),
            'Оплата' => $payment,
            'Комментарий' => $order->shipping_comment,
        ], self::adminUrl(OrderResource::class, $order));
    }

    public static function callback(CallbackRequest $request): string
    {
        return self::compose('Заявка на обратный звонок', [
            'Имя' => $request->name,
            'Телефон' => self::phone($request->phone),
            'Почта' => $request->email,
            'Город' => $request->city,
            'Время звонка' => $request->call_time,
            'Товар' => self::product($request->product),
            'Комментарий' => $request->comments,
        ], self::adminUrl(CallbackRequestResource::class, $request));
    }

    /** @param array<string, mixed> $fields */
    private static function compose(string $title, array $fields, string $url): string
    {
        $lines = [];

        foreach ($fields as $label => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                $lines[] = $label.': '.$value;
            }
        }

        return implode("\n", [$title, '', ...$lines, '', $url]);
    }

    /** В базе телефоны лежат цифрами: без плюса MAX не предложит позвонить. */
    private static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : '+'.$digits;
    }

    private static function product(?Product $product): ?string
    {
        if ($product === null) {
            return null;
        }

        return $product->name.(filled($product->sku) ? ' (арт. '.$product->sku.')' : '');
    }

    private static function money(mixed $amount): string
    {
        $amount = (float) $amount;
        $decimals = floor($amount) === $amount ? 0 : 2;

        return number_format($amount, $decimals, ',', "\u{00A0}").' ₽';
    }

    /**
     * Ссылка на запись в админке. Из очереди у Filament может не
     * оказаться текущей панели, поэтому на такой случай есть запасной
     * прямой адрес: без ссылки уведомление полезно, но вдвое менее удобно.
     *
     * @param  class-string  $resource
     */
    private static function adminUrl(string $resource, Model $record): string
    {
        try {
            return $resource::getUrl('view', ['record' => $record]);
        } catch (Throwable) {
            return url('/admin/'.$resource::getSlug().'/'.$record->getKey());
        }
    }
}
