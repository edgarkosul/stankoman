<?php

namespace App\Services\Notifications;

use App\Services\Notifications\Contracts\EscalationNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пуш в MAX (`platform-api.max.ru`).
 *
 * Обычный HTTP-клиент по образцу `app/Support/CompanyLookupService.php`:
 * официальный SDK у MAX есть только на JS, а нам нужен один POST. Токен
 * выдаёт @MasterBot внутри мессенджера, ограничение платформы — 30 запросов
 * в секунду, до которых нам как до Луны.
 *
 * Класс намеренно ничего не знает про диалоги и модели: он умеет отправить
 * строку и ссылку. Что именно писать, решает джоба уведомлений.
 */
final class MaxNotifier implements EscalationNotifier
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly ?string $chatId,
        private readonly int $timeout,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->token) && filled($this->chatId);
    }

    public function send(string $text, ?string $url = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        // Разметки в MAX нет, поэтому ссылка идёт отдельной строкой:
        // клиент сам делает её кликабельной.
        $body = $url === null ? $text : $text."\n".$url;

        try {
            // Авторизация у MAX — параметром запроса, а не заголовком:
            // access_token в строке, получатель тем же способом.
            $response = Http::timeout($this->timeout)
                ->connectTimeout(min(5, $this->timeout))
                ->withQueryParameters([
                    'access_token' => (string) $this->token,
                    'chat_id' => (string) $this->chatId,
                ])
                ->asJson()
                ->post($this->baseUrl.'/messages', ['text' => $body])
                ->throw();

            return $response->successful();
        } catch (Throwable $e) {
            // Пуш — best-effort: источник истины по эскалации это
            // уведомление в админке, и оно уже отправлено.
            Log::warning('MAX push failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
