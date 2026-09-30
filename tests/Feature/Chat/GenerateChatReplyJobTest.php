<?php

use App\Jobs\GenerateChatReplyJob;
use App\Models\AiUsageEntry;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Ai\ShopAssistant;
use App\Services\Chat\ChatConversationService;

/*
 * Джоба запускается руками через контейнер: очередь здесь не нужна, нужен
 * исход в ленте. Модель — FakeLlmClient (AI_EMBEDDING_FAKE=true в phpunit.xml):
 * отвечает заглушкой без сети, векторы детерминированы.
 *
 * Правило, которое тут держится: посетитель смотрит на индикатор, поэтому
 * любой исход обязан кончиться сообщением в ленте, кроме тех, где отвечать
 * уже некому.
 */

function chatVisitorQuestion(array $conversation = [], string $body = 'Какая у вас доставка?'): ChatMessage
{
    $chat = app(ChatConversationService::class);
    $message = $chat->addVisitorMessage(ChatConversation::factory()->create($conversation), $body);
    $chat->markPending($message->conversation);

    return $message;
}

function runChatReplyJob(ChatMessage $question): void
{
    app()->call([new GenerateChatReplyJob($question->chat_conversation_id, $question->id), 'handle']);
}

/** Шлюз, который не отвечает на вопросы, но векторы считать умеет. */
function chatBrokenGateway(): LlmClient
{
    return new class implements LlmClient
    {
        private FakeLlmClient $fake;

        public function __construct()
        {
            $this->fake = new FakeLlmClient((int) config('ai_support.embedding.dimensions'));
        }

        public function chat(
            string $system,
            array $messages,
            array $tools = [],
            ?int $maxTokens = null,
            ?string $sessionId = null,
            ?string $toolChoice = null,
        ): ChatResult {
            throw new LlmException('Шлюз не ответил.');
        }

        public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
        {
            return $this->fake->embed($texts, $mode);
        }

        public function chatModel(): string
        {
            return $this->fake->chatModel();
        }

        public function embeddingModel(): string
        {
            return $this->fake->embeddingModel();
        }

        public function embeddingDimensions(): int
        {
            return $this->fake->embeddingDimensions();
        }
    };
}

it('отвечает в ленту, пишет расходную книгу и снимает пометку «готовится»', function (): void {
    $question = chatVisitorQuestion();
    $conversation = $question->conversation;

    runChatReplyJob($question);

    $answer = $conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->sole();
    $conversation->refresh();

    expect($answer->stop_reason)->not->toBeIn(ChatMessage::FAILED_STOP_REASONS)
        ->and(trim($answer->body))->not->toBe('')
        // Вектор — от слов покупателя, на каждый ответ: сырьё «Пробелов».
        ->and($answer->getRawOriginal('embedding'))->not->toBeNull()
        ->and($conversation->unread_for_visitor)->toBe(1)
        ->and($conversation->escalated_at)->toBeNull()
        ->and(AiUsageEntry::query()->sole()->conversation_ref)->toBe($conversation->id)
        ->and(app(ChatConversationService::class)->isPending($conversation))->toBeFalse();
});

it('на сбой шлюза отвечает человеческой фразой и передаёт вопрос менеджеру', function (): void {
    app()->instance(LlmClient::class, chatBrokenGateway());

    $question = chatVisitorQuestion();
    $conversation = $question->conversation;

    runChatReplyJob($question);

    $answer = $conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->sole();

    expect($answer->body)->toStartWith('Не получается ответить прямо сейчас.')
        ->and($answer->stop_reason)->toBeIn(ChatMessage::FAILED_STOP_REASONS)
        ->and($answer->isRateable())->toBeFalse()
        ->and($conversation->fresh()->escalated_at)->not->toBeNull()
        ->and($conversation->messages()->where('role', ChatMessage::ROLE_SYSTEM)->sole()->meta['trigger'] ?? null)->toBe('failure')
        ->and(app(ChatConversationService::class)->isPending($conversation))->toBeFalse();
});

it('молчит в разговоре, который уже ведёт менеджер', function (): void {
    $question = chatVisitorQuestion(['status' => ChatConversation::STATUS_OPERATOR]);
    $conversation = $question->conversation;

    runChatReplyJob($question);

    expect($conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->count())->toBe(0)
        ->and(app(ChatConversationService::class)->isPending($conversation))->toBeFalse();
});

it('не отвечает на вопрос, который перебит следующим, и не снимает ожидание', function (): void {
    $first = chatVisitorQuestion();
    $conversation = $first->conversation;
    app(ChatConversationService::class)->addVisitorMessage($conversation->fresh(), 'И ещё: есть самовывоз?');

    runChatReplyJob($first);

    // На обе реплики ответит джоба второй — одной историей.
    expect($conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->count())->toBe(0)
        ->and(app(ChatConversationService::class)->isPending($conversation))->toBeTrue();
});

it('с выключенным ботом не молчит, а передаёт вопрос менеджеру', function (): void {
    config(['ai_support.agent.enabled' => false]);

    $question = chatVisitorQuestion();

    runChatReplyJob($question);

    expect($question->conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->sole()->stop_reason)->toBe('disabled')
        ->and($question->conversation->fresh()->escalated_at)->not->toBeNull();
});

it('повтор первого вопроса отдаёт из кэша без вызова модели', function (): void {
    runChatReplyJob(chatVisitorQuestion(body: 'Как оплатить заказ?'));

    $firstAnswer = ChatMessage::query()->where('role', ChatMessage::ROLE_ASSISTANT)->sole();

    // Второй разговор: шлюз лежит, но отвечать ему и не придётся.
    app()->instance(LlmClient::class, chatBrokenGateway());
    app()->forgetInstance(ShopAssistant::class);

    $repeat = chatVisitorQuestion(body: 'как оплатить заказ');

    runChatReplyJob($repeat);

    $cached = $repeat->conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->sole();

    expect($cached->stop_reason)->toBe('cached')
        ->and($cached->body)->toBe($firstAnswer->body)
        ->and($cached->cost_rub)->toBe(0.0)
        // Бесплатный ход тоже в книге: иначе долю кэша не посчитать.
        ->and(AiUsageEntry::query()->count())->toBe(2);
});
