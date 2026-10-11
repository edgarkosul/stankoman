<?php

namespace App\Shop;

use App\Livewire\Common\RequestCallback;
use App\Models\CallbackRequest;
use Siteko\Chatbot\Chat\Contracts\LeadIntake;
use Siteko\Chatbot\Chat\Data\ClaimedLead;
use Siteko\Chatbot\Models\ChatConversation;

/**
 * Заявка «перезвоните» как форма контактов в чате.
 *
 * Форма та же, что на карточке товара, но свой экземпляр со своими
 * параметрами: чат просит почту (менеджер отвечает письмом), телефон
 * остаётся необязательным полем, а заявка помечается источником «чат».
 */
final class CallbackLeadIntake implements LeadIntake
{
    /** Сколько минут заявка ещё считается «только что оставленной». */
    private const CLAIM_WINDOW_MINUTES = 5;

    /** Комментарий заявки начинается с этого: менеджер видит, откуда вопрос. */
    private const TOPIC_PREFIX = 'Вопрос из чата: ';

    public function formComponent(): string
    {
        return 'common.request-callback';
    }

    /**
     * Тема — то, что бот назвал, предлагая форму: менеджер отвечает,
     * уже понимая вопрос.
     */
    public function formParameters(ChatConversation $conversation, ?string $topic): array
    {
        return [
            'topic' => filled($topic) ? self::TOPIC_PREFIX.trim($topic) : null,
            'source' => CallbackRequest::SOURCE_CHAT,
            'channel' => RequestCallback::CHANNEL_EMAIL,
        ];
    }

    /** Форма без разговора — когда бот выключен и вместо чата форма. */
    public function standaloneFormParameters(int $siteId, ?array $page, array $source): array
    {
        return [
            'topic' => null,
            'source' => CallbackRequest::SOURCE_CHAT,
            'channel' => RequestCallback::CHANNEL_EMAIL,
        ];
    }

    public function createdEvent(): string
    {
        return 'callback-request-created';
    }

    /**
     * Своя — значит создана только что, с того же адреса и формой из чата.
     *
     * Третье условие у донора отсутствовало. Событие формы слышно всей
     * странице, и заявка, оставленная кнопкой на карточке товара при
     * загруженной панели чата, прицепилась бы к разговору — хотя покупатель
     * о чате в этот момент и не думал. С панелью в своей рамке (`/chat/panel`)
     * событие до неё со страницы не доходит, но проверка дешёвая и остаётся.
     *
     * Адрес — этого запроса: привязку зовёт панель из того же браузера,
     * что отправил форму.
     */
    public function claim(int $leadId, ChatConversation $conversation): ?ClaimedLead
    {
        $request = CallbackRequest::query()->find($leadId);

        $mine = $request !== null
            && $request->source === CallbackRequest::SOURCE_CHAT
            && $request->created_at !== null
            && $request->created_at->greaterThan(now()->subMinutes(self::CLAIM_WINDOW_MINUTES))
            && (string) $request->ip_address === (string) request()->ip();

        return $mine ? new ClaimedLead((int) $request->getKey(), self::contactsLeft($request)) : null;
    }

    /**
     * Открыта — пока менеджер не ответил: бот не предлагает форму второй раз
     * и говорит, что заявка уже у менеджера. Ответил или отменил — разговор
     * снова может кончиться новой заявкой.
     */
    public function hasOpenLead(ChatConversation $conversation): bool
    {
        if ($conversation->lead_ref === null) {
            return false;
        }

        return CallbackRequest::query()
            ->whereKey($conversation->lead_ref)
            ->where('status', CallbackRequest::STATUS_PENDING)
            ->exists();
    }

    /**
     * Что именно оставил покупатель. У донора пометка до 13.09.2026 всегда
     * говорила «оставил телефон» — с тех пор как чат просит почту, это
     * чаще всего было неправдой.
     */
    private static function contactsLeft(CallbackRequest $request): string
    {
        return match (true) {
            filled($request->email) && filled($request->phone) => 'почту и телефон',
            filled($request->email) => 'почту',
            filled($request->phone) => 'телефон',
            default => 'контакты',
        };
    }
}
