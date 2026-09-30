<?php

namespace App\Listeners\Orders;

use App\Events\Orders\OrderSubmitted;
use App\Models\MessengerChannel;
use App\Services\Messengers\MessengerMessages;
use App\Services\Messengers\MessengerNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Новый заказ — в чаты MAX менеджеров.
 *
 * Отдельный слушатель, а не строчка в `SendOrderSubmittedEmails`: если MAX
 * отвалится, письма не должны зависеть от его повторов, и наоборот.
 *
 * Зависимость приходит конструктором, а НЕ вторым параметром `handle()`.
 * Слушателю Laravel передаёт только событие (`Dispatcher::createClassCallable`
 * и `CallQueuedListener::handle` зовут `handle(...$payload)`), и второй
 * обязательный параметр уронил бы задачу с `ArgumentCountError` — причём
 * молча, в `failed_jobs`, потому что уведомление в MAX никто не ждёт
 * на экране. Контейнер же сам слушатель создаёт, так что конструктор
 * инъекцию получает.
 */
class NotifyMessengersAboutOrder implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly MessengerNotifier $messengers) {}

    public function handle(OrderSubmitted $event): void
    {
        $this->messengers->notify(
            MessengerChannel::TOPIC_ORDERS,
            // Замыканием: текст собирается только если есть кому отправлять,
            // и лишнего запроса за позициями заказа не будет.
            fn (): string => MessengerMessages::order($event->order->fresh(['items']) ?? $event->order),
        );
    }
}
