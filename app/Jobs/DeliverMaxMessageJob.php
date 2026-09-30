<?php

namespace App\Jobs;

use App\Models\MessengerChannel;
use App\Services\Messengers\ChatGone;
use App\Services\Messengers\MaxClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Одно сообщение — в один чат MAX.
 *
 * Сбой у MAX обычно минутный, поэтому повторы быстрые. Если бота
 * выгнали из чата, повтор не поможет: канал выключается сразу, и причину
 * видно на странице «Уведомления в MAX».
 */
class DeliverMaxMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $channelId,
        public readonly string $text,
    ) {
        // Очередь по умолчанию, та же, что у писем о заказах: отдельная
        // ассистентская (`redis-assistant`) существует ради длинных вызовов
        // модели, а здесь один короткий POST.
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MaxClient $max): void
    {
        $channel = MessengerChannel::query()->find($this->channelId);

        // Чат отключили или удалили, пока джоба ждала очереди.
        if ($channel === null || ! $channel->enabled) {
            return;
        }

        try {
            $max->send($channel->chat_id, $this->text);
        } catch (ChatGone $e) {
            $channel->disable($e->getMessage());

            return;
        } catch (Throwable $e) {
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }

        $channel->forceFill(['last_sent_at' => now(), 'last_error' => null, 'failed_at' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        MessengerChannel::query()->whereKey($this->channelId)->update(['failed_at' => now()]);
    }
}
