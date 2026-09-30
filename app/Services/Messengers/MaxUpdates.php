<?php

namespace App\Services\Messengers;

use App\Models\MessengerChannel;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Что делать с обновлением от MAX — одинаково для webhook на проде
 * и опроса в деве.
 *
 * Нужны два вида событий. Первое: человек нажал «Запустить» по ссылке
 * с кодом, и чат надо подключить. Второе: бота остановили или удалили,
 * и чат надо выключить, иначе заказы уходили бы в пустоту.
 */
final class MaxUpdates
{
    /** Какие обновления просим у MAX — и в webhook, и в опросе. */
    public const TYPES = ['bot_started', 'bot_stopped', 'bot_removed'];

    public function __construct(
        private readonly MaxLinks $links,
        private readonly MaxClient $max,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        $chatId = (string) ($update['chat_id'] ?? '');

        if ($chatId === '') {
            return;
        }

        match ($update['update_type'] ?? null) {
            'bot_started' => $this->start(
                $chatId,
                trim((string) ($update['user']['name'] ?? $update['user']['first_name'] ?? '')) ?: 'Чат MAX',
                isset($update['payload']) ? (string) $update['payload'] : null,
            ),
            'bot_stopped', 'bot_removed' => $this->gone($chatId),
            default => null,
        };
    }

    private function start(string $chatId, string $label, ?string $code): void
    {
        if ($code === null || ! $this->links->consume($code)) {
            $this->reply($chatId, $code === null
                ? 'Этот бот присылает менеджерам магазина заказы и заявки с сайта. Подключается он из админки: раздел «Уведомления в MAX», кнопка «Подключить MAX».'
                : 'Ссылка устарела или уже использована. Откройте в админке «Уведомления в MAX» и нажмите «Подключить MAX» ещё раз.');

            return;
        }

        MessengerChannel::query()->updateOrCreate(
            ['chat_id' => $chatId],
            [
                'label' => mb_substr($label, 0, 190),
                'enabled' => true,
                'last_error' => null,
                'failed_at' => null,
            ],
        );

        $this->reply($chatId, 'Готово: сюда будут приходить заказы, заявки и вопросы менеджеру с сайта. '
            .'Что присылать, настраивается в админке, раздел «Уведомления в MAX».');
    }

    private function gone(string $chatId): void
    {
        MessengerChannel::query()
            ->where('chat_id', $chatId)
            ->where('enabled', true)
            ->first()
            ?->disable('Бота остановили или удалили из чата MAX.');
    }

    /** Ответ в чат — вежливость, а не часть подключения: его сбой ничего не отменяет. */
    private function reply(string $chatId, string $text): void
    {
        try {
            $this->max->send($chatId, $text);
        } catch (Throwable $e) {
            Log::info('MAX reply failed', ['error' => $e->getMessage()]);
        }
    }
}
