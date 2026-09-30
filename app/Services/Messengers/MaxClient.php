<?php

namespace App\Services\Messengers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bot API MAX (`platform-api.max.ru`).
 *
 * Официальный SDK у MAX есть только на JS, а нам нужны три запроса, так
 * что это обычный HTTP-клиент. Бот свой, магазина: завести его может
 * только проверенная организация, токен выдают в кабинете партнёра MAX.
 *
 * Токен — заголовком Authorization и без «Bearer»: параметр `access_token`
 * MAX объявил неподдерживаемым. Получатель — `chat_id` в строке запроса.
 * Номер чата руками никто не вписывает: менеджер жмёт в админке
 * «Подключить MAX», бот открывается ссылкой `?start=<код>`, и код
 * возвращается к нам в `bot_started.payload` (см. MaxUpdates).
 */
final class MaxClient
{
    /** Предел длины сообщения у MAX. */
    public const MAX_TEXT = 4000;

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $botLink,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            token: config('services.max.token'),
            botLink: config('services.max.bot_link'),
            baseUrl: rtrim((string) config('services.max.base_url', 'https://platform-api.max.ru'), '/'),
            timeout: max(1, (int) config('services.max.timeout', 8)),
        );
    }

    /** Без ссылки на бота подключить чат нечем, поэтому она обязательна наравне с токеном. */
    public function configured(): bool
    {
        return filled($this->token) && filled($this->botLink);
    }

    public function startUrl(string $code): string
    {
        return rtrim((string) $this->botLink, '/').'?start='.$code;
    }

    /**
     * @throws ChatGone бота остановили или удалили из чата
     * @throws RuntimeException сеть, лимиты, сбой MAX
     */
    public function send(string $chatId, string $text): void
    {
        $request = $this->request()->withQueryParameters(['chat_id' => $chatId]);

        $this->check(fn () => $request->post($this->baseUrl.'/messages', [
            'text' => mb_substr($text, 0, self::MAX_TEXT),
        ]));
    }

    /**
     * Long-poll обновлений — только для дева, см. `max:poll`.
     *
     * @return array{updates: list<array<string, mixed>>, marker: int|null}
     */
    public function updates(?int $marker, int $wait): array
    {
        $body = $this->check(fn () => $this->request($wait + 5)->get($this->baseUrl.'/updates', array_filter([
            'marker' => $marker,
            'timeout' => $wait,
            'types' => implode(',', MaxUpdates::TYPES),
        ], static fn ($value): bool => $value !== null)));

        return [
            'updates' => array_values((array) ($body['updates'] ?? [])),
            'marker' => isset($body['marker']) ? (int) $body['marker'] : $marker,
        ];
    }

    public function subscribe(string $url, string $secret): void
    {
        $this->check(fn () => $this->request()->post($this->baseUrl.'/subscriptions', [
            'url' => $url,
            'update_types' => MaxUpdates::TYPES,
            'secret' => $secret,
        ]));
    }

    private function request(?int $timeout = null): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Бот MAX не настроен: нужны MAX_BOT_TOKEN и MAX_BOT_LINK.');
        }

        return Http::timeout($timeout ?? $this->timeout)
            ->connectTimeout(min(5, $this->timeout))
            ->withHeaders(['Authorization' => (string) $this->token])
            ->asJson();
    }

    /**
     * @param  callable(): Response  $send
     * @return array<string, mixed>
     */
    private function check(callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw new RuntimeException('MAX не ответил: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        $message = (string) ($response->json('message') ?? 'HTTP '.$response->status());

        if (in_array($response->status(), [403, 404], true)) {
            throw new ChatGone('MAX: '.$message);
        }

        throw new RuntimeException('MAX: '.$message);
    }
}
