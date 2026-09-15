<?php

use App\Models\AiUsageEntry;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\Data\AssistantReply;
use Tests\TestCase;

// Модели без обращения к базе: контейнер нужен только ради now()
// и грамматики соединения.
uses(TestCase::class);

/** @param array<string, mixed> $attributes */
function chatBotMessage(array $attributes = []): ChatMessage
{
    return (new ChatMessage)->forceFill(array_merge([
        'id' => 1,
        'role' => ChatMessage::ROLE_ASSISTANT,
        'body' => 'Оплатить можно картой на сайте.',
        'stop_reason' => 'stop',
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function chatConversation(array $attributes = []): ChatConversation
{
    return (new ChatConversation)->forceFill(['id' => 1, 'token' => 'abc123', ...$attributes]);
}

it('предлагает оценить обычный ответ бота, но не заглушку и не реплику покупателя', function (): void {
    expect(chatBotMessage()->isRateable())->toBeTrue()
        ->and(chatBotMessage(['role' => ChatMessage::ROLE_VISITOR])->isRateable())->toBeFalse()
        ->and(chatBotMessage(['body' => '   '])->isRateable())->toBeFalse()
        ->and(chatBotMessage(['stop_reason' => 'handed_to_operator'])->isRateable())->toBeFalse();

    // Заглушка — признание, что ответа нет. Собранные по ней 👎 засоряли бы
    // «Пробелы» тем, что и так известно из stop_reason.
    foreach (ChatMessage::FAILED_STOP_REASONS as $reason) {
        expect(chatBotMessage(['stop_reason' => $reason])->isRateable())->toBeFalse();
    }
});

it('считает провалом и исключение, поймав которое джоба пишет заглушку', function (): void {
    // У донора 'exception' в списке не было, и под «Не получается ответить»
    // висели кнопки оценки.
    expect(ChatMessage::FAILED_STOP_REASONS)->toContain('exception');
});

it('держит оценку числом, иначе повторный клик не снимет её', function (): void {
    $message = chatBotMessage();
    $message->setRawAttributes(['rating' => '-1'], sync: true);

    expect($message->rating)->toBe(ChatMessage::RATING_DOWN);
});

it('не тащит вектор в ленту и не отдаёт его в JSON', function (): void {
    $sql = ChatMessage::query()->withoutEmbedding()->toSql();

    expect($sql)->not->toContain('embedding')
        ->and($sql)->toContain('"chat_messages"."chat_conversation_id"');

    $message = chatBotMessage();
    $message->setRawAttributes(['id' => 1, 'body' => 'текст', 'embedding' => "\xff\xfe\x00\x01"], sync: true);

    expect($message->toArray())->not->toHaveKey('embedding');
});

it('показывает покупателю только те служебные пометки, что написаны для него', function (): void {
    $internal = (new ChatMessage)->forceFill([
        'role' => ChatMessage::ROLE_SYSTEM,
        'body' => 'Оператор Эдгар взял разговор в работу.',
        'meta' => ['event' => 'taken_over'],
    ]);
    $forVisitor = (new ChatMessage)->forceFill([
        'role' => ChatMessage::ROLE_SYSTEM,
        'body' => 'Разговор возвращён консультанту.',
        'meta' => ['visitor_body' => 'Менеджер передал разговор консультанту.'],
    ]);

    expect($internal->visitorNote())->toBeNull()
        ->and($forVisitor->visitorNote())->toBe('Менеджер передал разговор консультанту.')
        // Реплика бота с подделанной мета-строкой не станет пометкой магазина.
        ->and(chatBotMessage(['meta' => ['visitor_body' => 'Разговор завершён.']])->visitorNote())->toBeNull();
});

it('даёт шлюзу стабильный ключ сессии, в котором нет самого токена', function (): void {
    // На этом стоит вся экономика кэша префикса, а токен даёт доступ к переписке.
    $conversation = chatConversation(['token' => 'secret-token-value']);

    expect($conversation->promptSessionId())->toBe($conversation->promptSessionId())
        ->toHaveLength(32)
        ->not->toContain('secret-token-value')
        ->and(chatConversation(['token' => 'другой'])->promptSessionId())->not->toBe($conversation->promptSessionId());
});

it('говорит, чем занят менеджер, только когда это правда', function (): void {
    $taken = chatConversation(['status' => ChatConversation::STATUS_OPERATOR, 'escalated_at' => now()]);
    $untaken = chatConversation(['status' => ChatConversation::STATUS_BOT, 'escalated_at' => now()]);
    $closed = chatConversation(['status' => ChatConversation::STATUS_CLOSED]);

    expect($taken->staffActivity(typing: false, lastMessageRole: ChatMessage::ROLE_VISITOR))->toBe(ChatConversation::STAFF_WORKING)
        ->and($taken->staffActivity(typing: true, lastMessageRole: ChatMessage::ROLE_VISITOR))->toBe(ChatConversation::STAFF_TYPING)
        // «В работе» под его же репликой означало бы, что ждать надо снова.
        ->and($taken->staffActivity(typing: false, lastMessageRole: ChatMessage::ROLE_OPERATOR))->toBeNull()
        // Переданный, но никем не взятый вопрос: за столом может не быть никого.
        ->and($untaken->staffActivity(typing: false, lastMessageRole: ChatMessage::ROLE_VISITOR))->toBeNull()
        ->and($untaken->staffActivity(typing: true, lastMessageRole: ChatMessage::ROLE_VISITOR))->toBe(ChatConversation::STAFF_TYPING)
        ->and($closed->staffActivity(typing: true, lastMessageRole: ChatMessage::ROLE_VISITOR))->toBeNull();
});

it('пишет в расходную книгу телеметрию хода и ничего из переписки', function (): void {
    $reply = new AssistantReply(
        text: 'Мой телефон 9161234567',
        stopReason: 'stop',
        inputTokens: 12_000,
        outputTokens: 300,
        cachedTokens: 9_000,
        costRub: 0.2734,
        latencyMs: 8_400,
        model: 'deepseek-v4-flash',
    );

    $row = AiUsageEntry::attributesFor($reply, conversationRef: 42);

    expect($row)->toMatchArray([
        'conversation_ref' => 42,
        'model' => 'deepseek-v4-flash',
        'stop_reason' => 'stop',
        'input_tokens' => 12_000,
        'cost_rub' => 0.2734,
        'escalated' => false,
    ])
        ->and(implode(' ', array_map(strval(...), $row)))->not->toContain('9161234567');
});

it('записывает бесплатный ход нулями и не пускает отрицательные числа', function (): void {
    $free = AiUsageEntry::attributesFor(null, 42, escalated: true, stopReason: 'cached');
    $broken = AiUsageEntry::attributesFor(new AssistantReply(text: '', stopReason: 'stop', inputTokens: -5, costRub: -1.0), 42);

    expect($free['stop_reason'])->toBe('cached')
        ->and($free['cost_rub'])->toBe(0.0)
        ->and($free['escalated'])->toBeTrue()
        ->and($broken['input_tokens'])->toBe(0)
        ->and($broken['cost_rub'])->toBe(0.0);
});
