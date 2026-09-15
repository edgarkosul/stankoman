<?php

namespace App\Shop;

use App\Livewire\Common\RequestCallback;
use App\Models\CallbackRequest;
use App\Services\Chat\Contracts\LeadIntake;
use App\Services\Chat\Data\ClaimedLead;

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

    public function formComponent(): string
    {
        return 'common.request-callback';
    }

    public function formParameters(?string $topic): array
    {
        return [
            'topic' => $topic,
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
     * о чате в этот момент и не думал.
     */
    public function claim(int $leadId, ?string $ip): ?ClaimedLead
    {
        $request = CallbackRequest::query()->find($leadId);

        $mine = $request !== null
            && $request->source === CallbackRequest::SOURCE_CHAT
            && $request->created_at !== null
            && $request->created_at->greaterThan(now()->subMinutes(self::CLAIM_WINDOW_MINUTES))
            && (string) $request->ip_address === (string) $ip;

        return $mine ? new ClaimedLead((int) $request->getKey(), self::contactsLeft($request)) : null;
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
