<?php

use App\Jobs\GenerateChatReplyJob;
use App\Jobs\StoreQuestionVectorJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Services\Chat\ChatConversationService;
use App\Services\Kb\KbVectorStore;
use Illuminate\Support\Facades\Queue;

/*
 * Вектор вопроса к ответу бота — сырьё «Пробелов». Считается своей джобой
 * в очереди по умолчанию, уже после того, как ответ лёг в ленту.
 */

/** @return array{0: ChatMessage, 1: ChatMessage} вопрос покупателя и ответ бота */
function vectorExchange(string $question = 'Какая у вас доставка?'): array
{
    $chat = app(ChatConversationService::class);
    $conversation = ChatConversation::factory()->create();
    $visitor = $chat->addVisitorMessage($conversation, $question);
    $answer = $chat->addAssistantMessage($conversation->fresh(), 'Доставляем по всей России.');

    return [$visitor, $answer];
}

function runVectorJob(ChatMessage $question, ChatMessage $answer): void
{
    app()->call([new StoreQuestionVectorJob($answer->id, $question->id), 'handle']);
}

/** Шлюз поверх FakeLlmClient (он final); $embeds = false — embed() падает, как лежащий шлюз. */
function useVectorGateway(bool $embeds = true): void
{
    app()->instance(LlmClient::class, new class($embeds) implements LlmClient
    {
        private FakeLlmClient $fake;

        public function __construct(private bool $embeds)
        {
            $this->fake = new FakeLlmClient((int) config('ai_support.embedding.dimensions'));
        }

        public function chat(string $system, array $messages, array $tools = [], ?int $maxTokens = null, ?string $sessionId = null, ?string $toolChoice = null): ChatResult
        {
            return $this->fake->chat($system, $messages, $tools, $maxTokens, $sessionId, $toolChoice);
        }

        public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
        {
            if (! $this->embeds) {
                throw new LlmException('Шлюз эмбеддингов не ответил.');
            }

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
    });
}

it('дописывает к ответу вектор слов покупателя', function (): void {
    [$question, $answer] = vectorExchange();

    runVectorJob($question, $answer);

    $expected = app(KbVectorStore::class)->embedQuery('Какая у вас доставка?');

    expect(KbVectorStore::unpackVector($answer->fresh()->getRawOriginal('embedding')))
        ->toEqualWithDelta($expected, 1e-6);
});

it('лежащий шлюз не затирает запасной вектор и не роняет джобу', function (): void {
    useVectorGateway(embeds: false);

    [$question, $answer] = vectorExchange();
    $fallback = KbVectorStore::packVector([0.6, 0.8]);
    $answer->forceFill(['embedding' => $fallback])->save();

    runVectorJob($question, $answer);

    expect($answer->fresh()->getRawOriginal('embedding'))->toBe($fallback);
});

it('переписка, очищенная до джобы, — не ошибка', function (): void {
    [$question, $answer] = vectorExchange();
    $answer->conversation->messages()->delete();

    runVectorJob($question, $answer);

    expect(ChatMessage::query()->count())->toBe(0);
});

it('джоба ответа не ждёт вектора: ставит его в очередь по умолчанию после записи ответа', function (): void {
    Queue::fake([StoreQuestionVectorJob::class]);

    $chat = app(ChatConversationService::class);
    $question = $chat->addVisitorMessage(ChatConversation::factory()->create(), 'Есть ли самовывоз?');
    $chat->markPending($question->conversation);

    app()->call([new GenerateChatReplyJob($question->chat_conversation_id, $question->id), 'handle']);

    $answer = $question->conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->sole();

    Queue::assertPushed(StoreQuestionVectorJob::class, fn (StoreQuestionVectorJob $job): bool => $job->answerMessageId === $answer->id
        && $job->questionMessageId === $question->id
        // Не на соединении ответов: воркер redis-assistant на вектор не тратится.
        && $job->connection === null);
});
