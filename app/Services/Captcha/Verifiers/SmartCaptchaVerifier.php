<?php

namespace App\Services\Captcha\Verifiers;

use App\Services\Captcha\Contracts\CaptchaVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Яндекс SmartCaptcha.
 *
 * Один POST формой на `/validate` — SDK для PHP у сервиса нет, готовые
 * пакеты для Laravel есть, но весь их полезный объём это тот же запрос,
 * и тянуть ради него зависимость в боевой магазин незачем.
 *
 * Ответ бинарный, без скоринга: `ok` — человек, `failed` — либо робот, либо
 * ошибка в самом запросе. Различить их можно только по `message`: у робота
 * он пустой, у ошибки — с текстом. Разница важнее, чем кажется: неверный
 * серверный ключ выглядит в статистике ровно как нашествие ботов, поэтому
 * непустой `message` уходит в лог.
 */
final class SmartCaptchaVerifier implements CaptchaVerifier
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $validateUrl,
        private readonly float $timeout,
    ) {}

    public function verify(string $token, ?string $ip = null): bool
    {
        // Пустой токен до сервиса не доводим: ответ известен заранее, а
        // тарифицируются только успешные проверки — незачем и запрос тратить.
        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->connectTimeout(min(5.0, $this->timeout))
                ->post($this->validateUrl, array_filter([
                    'secret' => $this->secretKey,
                    'token' => $token,
                    'ip' => $ip,
                ], static fn ($value): bool => $value !== null && $value !== ''));
        } catch (Throwable $e) {
            return $this->passOnOutage('запрос не ушёл', ['error' => $e->getMessage()]);
        }

        /*
         * Сервис недоступен — пропускаем.
         *
         * Это прямая рекомендация Яндекса: считать ответ не-200 успешным,
         * чтобы не задерживать посетителей на время аварии. Решение звучит
         * рискованно ровно до тех пор, пока не вспомнить, что капча тут не
         * единственная защита: у чата над ней стоит ChatAbuseGuard, а под
         * ним `limit_req` в nginx. Обратный выбор означал бы, что один
         * сетевой сбой у третьей стороны выключает чат целиком.
         */
        if (! $response->successful()) {
            return $this->passOnOutage('сервис ответил ошибкой', ['status' => $response->status()]);
        }

        $status = (string) $response->json('status', '');
        $message = trim((string) $response->json('message', ''));

        if ($status === 'ok') {
            return true;
        }

        // Непустой message = мы сами что-то прислали не так: битый ключ,
        // протухший или уже использованный токен. Посетителя это всё равно
        // не пускает, но разбираться потом придётся по этой записи.
        if ($message !== '') {
            Log::warning('SmartCaptcha отклонила запрос', [
                'message' => $message,
                'status' => $status,
            ]);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function passOnOutage(string $reason, array $context): bool
    {
        Log::warning("SmartCaptcha недоступна ({$reason}) — посетитель пропущен", $context);

        return true;
    }
}
