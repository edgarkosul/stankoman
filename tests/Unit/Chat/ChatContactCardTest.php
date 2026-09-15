<?php

use App\Livewire\Common\RequestCallback;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatContactCard;
use Illuminate\Support\Collection;
use Tests\TestCase;

// Модели без базы: контейнер нужен только ради now().
uses(TestCase::class);

/**
 * Лента из несохранённых сообщений: каждый элемент — [роль, meta].
 *
 * @param  list<array{0: string, 1?: array<string, mixed>}>  $rows
 * @return Collection<int, ChatMessage>
 */
function chatContactFeed(array $rows): Collection
{
    return collect($rows)->map(static fn (array $row): ChatMessage => (new ChatMessage)->forceFill([
        'role' => $row[0],
        'body' => 'текст',
        'meta' => $row[1] ?? null,
    ]));
}

/** @param array<string, mixed> $attributes */
function chatContactConversation(array $attributes = []): ChatConversation
{
    return (new ChatConversation)->forceFill([
        'id' => 1,
        'status' => ChatConversation::STATUS_BOT,
        'assistant_enabled' => true,
        ...$attributes,
    ]);
}

it('держит карточку после того, как покупатель дописал реплику', function (): void {
    // Диалог 17 донора: бот предложил форму, покупатель ответил —
    // и форма пропала вместе с темой.
    $card = ChatContactCard::for(chatContactConversation(), chatContactFeed([
        [ChatMessage::ROLE_VISITOR],
        [ChatMessage::ROLE_ASSISTANT, ['callback_requested' => true, 'callback_topic' => 'Счёт на станок']],
        [ChatMessage::ROLE_VISITOR],
        [ChatMessage::ROLE_ASSISTANT],
        [ChatMessage::ROLE_VISITOR],
    ]), online: true);

    expect($card->hint)->toBe('Оставьте почту — менеджер ответит письмом и поможет.')
        ->and($card->topic)->toBe('Вопрос из чата: Счёт на станок');
});

it('убирает карточку, когда заявка оформлена', function (): void {
    $card = ChatContactCard::for(chatContactConversation(['callback_request_id' => 222]), chatContactFeed([
        [ChatMessage::ROLE_ASSISTANT, ['callback_requested' => true, 'callback_topic' => 'Счёт']],
    ]), online: true);

    expect($card->hint)->toBeNull()->and($card->topic)->toBeNull();
});

it('показывает форму, когда покупатель написал контакт в сообщении, и называет кнопку', function (): void {
    $card = ChatContactCard::for(chatContactConversation(), chatContactFeed([
        [ChatMessage::ROLE_ASSISTANT],
        [ChatMessage::ROLE_VISITOR, ['contact_in_chat' => true]],
    ]), online: false);

    // Формы на экране не видно, пока не нажата кнопка: подводка обязана
    // назвать кнопку, а не «форму».
    expect($card->hint)->toStartWith('Похоже, вы написали контакт в сообщении.')
        ->and($card->hint)->toContain('«'.RequestCallback::CONTACT_BUTTON.'»');
});

it('берёт тему с последнего сообщения, где она есть', function (): void {
    $card = ChatContactCard::for(chatContactConversation(), chatContactFeed([
        [ChatMessage::ROLE_ASSISTANT, ['callback_requested' => true, 'callback_topic' => 'Первая тема']],
        [ChatMessage::ROLE_ASSISTANT, ['callback_requested' => true, 'callback_topic' => 'Доставка в Ярославль']],
        [ChatMessage::ROLE_VISITOR],
    ]), online: true);

    expect($card->topic)->toBe('Вопрос из чата: Доставка в Ярославль');
});

it('молчит в разговоре у человека, пока контакты не просили', function (): void {
    $card = ChatContactCard::for(
        chatContactConversation(['status' => ChatConversation::STATUS_OPERATOR, 'escalated_at' => now()]),
        chatContactFeed([[ChatMessage::ROLE_VISITOR], [ChatMessage::ROLE_OPERATOR]]),
        online: true,
    );

    expect($card->hint)->toBeNull();
});

it('после эскалации вне смены обещает ответ в рабочее время', function (): void {
    $card = ChatContactCard::for(
        chatContactConversation(['escalated_at' => now()]),
        chatContactFeed([[ChatMessage::ROLE_VISITOR]]),
        online: false,
    );

    expect($card->hint)->toContain('нерабочее время');
});

it('без повода карточку не показывает', function (): void {
    expect(ChatContactCard::for(chatContactConversation(), chatContactFeed([
        [ChatMessage::ROLE_VISITOR],
        [ChatMessage::ROLE_ASSISTANT],
    ]), online: true)->hint)->toBeNull()
        ->and(ChatContactCard::for(null, collect(), online: true)->hint)->toBeNull();
});
