<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Chat\ChatConversationService;
use App\Services\Kb\KbVectorStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вектор вопроса покупателя — к ответу бота, сырьё «Пробелов».
 *
 * От слов ПОКУПАТЕЛЯ и на каждый ответ. У донора вектор был побочным
 * продуктом поиска по базе знаний, и это давало две дыры: у товарных
 * вопросов его не было вовсе, а у остальных он описывал формулировку
 * самого бота. На проде донора — 2 вектора на 46 сообщений, то есть
 * «Пробелы» видели меньше десятой части потока.
 *
 * Своей джобой, а не аргументом записи ответа в GenerateChatReplyJob.
 * Там вектор считался между готовым ответом и лентой, в воркере ответов,
 * а шлюз эмбеддингов бывает медленным — до 30 с с таймаутом и повтором.
 * В bots это стоило 16 с между ответом и лентой (28.09.2026) и 14 с
 * ожидания следующего вопроса того же посетителя (06.10.2026), хотя
 * модель ответила за 10,7 с. «Пробелам» вектор нужен не сразу,
 * покупателю ответ — сразу.
 *
 * Очередь по умолчанию, как у уведомлений менеджерам: воркер ответов
 * (redis-assistant) на это не тратится.
 */
class StoreQuestionVectorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Не повторяем: сбой шлюза и так проглочен ниже, а вектор, которого
     * нет, стоит одной строки в «Пробелах», а не ответа покупателю.
     */
    public int $tries = 1;

    /**
     * Потолок вектора вопроса — (query_retries + 1) × query_timeout, 30 с.
     * Ниже retry_after очереди по умолчанию (90) с запасом.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly int $answerMessageId,
        public readonly int $questionMessageId,
    ) {}

    public function handle(ChatConversationService $chat): void
    {
        // Пока джоба ждала очереди, покупатель мог очистить переписку.
        $answer = ChatMessage::query()->find($this->answerMessageId);
        $question = ChatMessage::query()->find($this->questionMessageId);

        if ($answer === null || $question === null) {
            return;
        }

        $chat->storeQuestionVector($answer, $this->vector($question));
    }

    /**
     * Сбой шлюза здесь не должен стоить ничего: считать вектор мы пытаемся
     * и тогда, когда шлюз, возможно, уже лежит (заглушка провала).
     * Не получилось — ответ остаётся с тем вектором, что был (запасной
     * из поиска по базе), или без него.
     *
     * @return list<float>|null
     */
    private function vector(ChatMessage $question): ?array
    {
        $text = trim((string) $question->body);

        if ($text === '') {
            return null;
        }

        try {
            $vector = app(KbVectorStore::class)->embedQuery(
                app(PiiRedactor::class)->redact($text),
            );
        } catch (Throwable $e) {
            Log::warning('Не смог посчитать вектор вопроса', [
                'message_id' => $question->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $vector === [] ? null : $vector;
    }
}
