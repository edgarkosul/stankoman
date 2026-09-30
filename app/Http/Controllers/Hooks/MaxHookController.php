<?php

namespace App\Http\Controllers\Hooks;

use App\Http\Controllers\Controller;
use App\Services\Messengers\MaxUpdates;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обновления от бота MAX (webhook, регистрирует `max:hook`).
 *
 * Маршрут в api-группе: ни сессии, ни CSRF. Что запрос пришёл от MAX,
 * подтверждает секрет в заголовке; мы сами передали его при регистрации
 * webhook. Если секрет в конфигурации не задан, хук закрыт: пустая строка
 * совпала бы с пустым заголовком.
 *
 * При верном секрете ответ всегда 200. Ошибка разбора — наша, повтор от
 * MAX её не исправит, а после восьми часов неудачных повторов MAX
 * снимает подписку совсем.
 */
class MaxHookController extends Controller
{
    public const SECRET_HEADER = 'X-Max-Bot-Api-Secret';

    public function __invoke(Request $request, MaxUpdates $updates): Response
    {
        if (! self::authorized((string) config('services.max.webhook_secret'), $request->header(self::SECRET_HEADER))) {
            return response('', 403);
        }

        try {
            $updates->handle((array) $request->json()->all());
        } catch (Throwable $e) {
            Log::warning('MAX update failed', ['error' => $e->getMessage()]);
        }

        return response('', 200);
    }

    public static function authorized(string $secret, ?string $header): bool
    {
        return $secret !== '' && hash_equals($secret, (string) $header);
    }
}
