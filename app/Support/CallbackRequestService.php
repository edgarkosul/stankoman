<?php

namespace App\Support;

use App\Events\CallbackRequests\CallbackRequestSubmitted;
use App\Models\CallbackRequest;
use Illuminate\Support\Str;

/**
 * Один вход для заявки «свяжитесь со мной» — и с витрины, и из чата.
 *
 * Контакты сюда приходят уже проверенными формой: какое поле обязательно,
 * решает она, потому что только она знает канал ответа.
 */
class CallbackRequestService
{
    /**
     * @param  array{name?:?string, phone?:?string, email?:?string, city?:?string, call_time?:?string, comments?:?string}  $contact
     * @param  array{source?:string, product_id?:?int, user_id?:?int, ip_address?:?string, user_agent?:?string}  $context
     */
    public function submit(array $contact, array $context = []): CallbackRequest
    {
        $phone = $this->nullableString($contact['phone'] ?? null);
        $email = $this->nullableString($contact['email'] ?? null);
        $email = $email !== null ? Str::lower($email) : null;

        $source = $context['source'] ?? CallbackRequest::SOURCE_SITE;

        if (! array_key_exists($source, CallbackRequest::sourceLabels())) {
            $source = CallbackRequest::SOURCE_SITE;
        }

        $callbackRequest = CallbackRequest::query()->create([
            'user_id' => $context['user_id'] ?? null,
            'product_id' => $context['product_id'] ?? null,
            'name' => $this->nullableString($contact['name'] ?? null),
            'phone' => $phone,
            'phone_hash' => self::phoneHash($phone),
            'email' => $email,
            'email_hash' => self::emailHash($email),
            'city' => $this->nullableString($contact['city'] ?? null),
            'call_time' => $this->nullableString($contact['call_time'] ?? null),
            'comments' => $this->nullableString($contact['comments'] ?? null),
            'source' => $source,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => filled($context['user_agent'] ?? null)
                ? Str::limit((string) $context['user_agent'], 250, '')
                : null,
        ]);

        CallbackRequestSubmitted::dispatch($callbackRequest);

        return $callbackRequest;
    }

    /**
     * Хэш по цифрам, а не по строке: «+7 (999) …» и «79990…» — один человек.
     */
    public static function phoneHash(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits !== '' ? hash('sha256', $digits) : null;
    }

    public static function emailHash(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email !== '' ? hash('sha256', $email) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
