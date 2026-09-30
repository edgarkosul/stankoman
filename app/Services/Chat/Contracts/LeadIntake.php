<?php

namespace App\Services\Chat\Contracts;

use App\Services\Chat\Data\ClaimedLead;

/**
 * Форма «оставьте контакты», встроенная в чат.
 *
 * Шов между чатом и заявками магазина. Контакты покупатель вводит в форму
 * магазина, а не в переписку: в историю для модели они не попадают вообще,
 * а менеджер получает их заявкой. Какая это форма, как называется заявка
 * и что в ней обязательно, знает магазин (App\Shop\CallbackLeadIntake).
 */
interface LeadIntake
{
    /** Имя Livewire-компонента формы. */
    public function formComponent(): string;

    /**
     * Параметры экземпляра формы в чате.
     *
     * @param  string|null  $topic  тема, которую назвал бот, — ляжет в комментарий заявки
     * @return array<string, mixed>
     */
    public function formParameters(?string $topic): array;

    /**
     * Событие, которым форма сообщает о созданной заявке. Номер заявки
     * приходит в нём параметром `requestId`.
     */
    public function createdEvent(): string;

    /**
     * Заявка из события — если она действительно этого посетителя.
     *
     * Номер приходит от клиента, поэтому верить ему нельзя: без проверки
     * посетитель подставил бы в свой диалог чужую заявку. Прочитать её он
     * всё равно не сможет, но менеджер увидел бы в разговоре чужие контакты.
     */
    public function claim(int $leadId, ?string $ip): ?ClaimedLead;
}
