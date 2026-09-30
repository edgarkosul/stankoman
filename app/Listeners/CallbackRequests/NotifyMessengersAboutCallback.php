<?php

namespace App\Listeners\CallbackRequests;

use App\Events\CallbackRequests\CallbackRequestSubmitted;
use App\Models\ChatConversation;
use App\Models\MessengerChannel;
use App\Services\Messengers\MessengerMessages;
use App\Services\Messengers\MessengerNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Заявка на обратный звонок — в чаты MAX.
 *
 * Слушатель события, а не вызов из формы: заявку принимает один сервис
 * (`CallbackRequestService::submit`), и он же бросает событие — значит
 * уведомление уйдёт и с карточки товара, и из чата, и откуда угодно ещё,
 * без второй копии этого решения на каждой форме.
 *
 * Задержка перед отправкой не косметика. Та же форма живёт внутри панели
 * чата, и там заявку привязывают к диалогу уже ПОСЛЕ создания. По такой
 * заявке менеджеру и так приходит эскалация «из чата оставили контакты»
 * со ссылкой на переписку, где видно, о чём спрашивали, — а второе
 * сообщение об этой же заявке было бы дублем без контекста. Поэтому
 * ждём привязки и, если она случилась, молчим.
 *
 * Зависимость приходит конструктором: слушателю Laravel передаёт только
 * событие, второй обязательный параметр `handle()` уронил бы задачу
 * в `failed_jobs` — молча, потому что пуша в мессенджер никто не ждёт
 * на экране.
 */
class NotifyMessengersAboutCallback implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    /** Сколько ждать, пока чат привяжет заявку к диалогу, секунды. */
    public int $delay = 15;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(private readonly MessengerNotifier $messengers) {}

    public function handle(CallbackRequestSubmitted $event): void
    {
        $request = $event->callbackRequest->fresh(['product']);

        if ($request === null) {
            return;
        }

        // Заявку забрал чат — про неё уже написала эскалация.
        if (ChatConversation::query()->where('callback_request_id', $request->getKey())->exists()) {
            return;
        }

        $this->messengers->notify(
            MessengerChannel::TOPIC_REQUESTS,
            fn (): string => MessengerMessages::callback($request),
        );
    }
}
