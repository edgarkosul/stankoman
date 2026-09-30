<?php

namespace App\Services\Kb;

use App\Models\ChatMessage;
use App\Services\Kb\Data\KbGapQuestion;
use App\Services\Kb\Data\KbGapReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «Пробелы в базе знаний»: из переписки — очередь работ.
 *
 * Читает четыре сигнала (плохая оценка, бот позвал человека, пустой поиск
 * по базе, ответ менеджера с пометкой «в базу»), поднимает к каждому реплику
 * покупателя и группирует получившееся по смыслу.
 *
 * Ни одного вызова шлюза: всё, что нужно, посчитано и сохранено в момент
 * ответа. Экран, за открытие которого платят деньгами, открывали бы
 * с опаской — а этот должен открываться каждое утро.
 */
final class KbGapAnalyzer
{
    /** Сигнал, по которому можно отфильтровать отчёт целиком. */
    public const SIGNALS = [
        KbGapQuestion::SIGNAL_RATED_DOWN => 'Оценено плохо',
        KbGapQuestion::SIGNAL_ESCALATED => 'Бот позвал человека',
        KbGapQuestion::SIGNAL_KB_MISS => 'Ничего не нашёл в базе',
        KbGapQuestion::SIGNAL_OPERATOR_ANSWER => 'Ответил менеджер',
    ];

    public function __construct(
        private readonly KbGapClusterer $clusterer,
        /**
         * Потолок разбираемых сигналов. Нужен не ради скорости перебора
         * (он копеечный), а ради самого экрана: очередь работ длиной
         * в тысячу строк — это не очередь. Берутся самые свежие, и о том,
         * что взяты не все, экран говорит вслух.
         */
        private readonly int $maxSignals,
    ) {}

    /**
     * @param  int|null  $days  окно наблюдения; null — за всё время
     * @param  string|null  $signal  один из ключей SIGNALS; null — любой
     */
    public function report(?int $days = 30, ?string $signal = null): KbGapReport
    {
        $messages = $this->signalMessages($days, $signal);

        $truncated = $messages->count() > $this->maxSignals;
        $messages = $messages->take($this->maxSignals);

        if ($messages->isEmpty()) {
            return new KbGapReport;
        }

        $questions = $this->buildQuestions($messages);

        $ungrouped = array_values(array_filter(
            $questions,
            static fn (KbGapQuestion $q): bool => ! $q->isClusterable(),
        ));

        usort(
            $ungrouped,
            static fn (KbGapQuestion $a, KbGapQuestion $b): int => $b->askedAt->getTimestamp() <=> $a->askedAt->getTimestamp(),
        );

        return new KbGapReport(
            clusters: $this->clusterer->cluster($questions),
            ungrouped: $ungrouped,
            truncated: $truncated,
        );
    }

    /**
     * Ответы бота и менеджеров, отмеченные хотя бы одним сигналом.
     *
     * @return Collection<int, ChatMessage>
     */
    private function signalMessages(?int $days, ?string $signal): Collection
    {
        return ChatMessage::query()
            ->whereIn('role', [ChatMessage::ROLE_ASSISTANT, ChatMessage::ROLE_OPERATOR])
            ->when($days !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', now()->subDays($days)))
            ->where(fn (Builder $query) => $this->applySignals($query, $signal))
            // Берём свежие: если сигналов больше потолка, отрезать надо хвост,
            // а не голову — старое либо уже разобрано, либо не будет разобрано.
            ->orderByDesc('id')
            ->limit($this->maxSignals + 1)
            ->get(['id', 'chat_conversation_id', 'role', 'body', 'created_at', 'rating', 'kb_miss', 'tool_calls', 'meta', 'embedding']);
    }

    private function applySignals(Builder $query, ?string $signal): Builder
    {
        $ratedDown = fn (Builder $q): Builder => $q->where('rating', '<', 0);
        $kbMiss = fn (Builder $q): Builder => $q->where('kb_miss', true);

        /*
         * «Позвал человека» — это ТОЛЬКО escalate_to_operator, но не
         * request_contact, хотя оба ведут к менеджеру. Разобрано у донора
         * на живой переписке 31.08.2026, и разница оказалась принципиальной.
         *
         * `escalate_to_operator` — признание бота: «ответа у меня нет».
         * `request_contact` — механика сбора контактов, и срабатывает она
         * дважды мимо цели. Во-первых, на «свяжитесь со мной» после хорошего
         * ответа: покупатель просто хочет человека, дыры в базе тут нет.
         * Во-вторых, следом за уже посчитанной эскалацией — о том же самом.
         * В разобранном диалоге донора четыре сигнала из шести оказались
         * такими, то есть шум перевесил бы сигнал.
         */
        $escalated = fn (Builder $q): Builder => $q->where('meta->escalated', true);

        // Ответ менеджера, отмеченный «в базу», — единственный сигнал,
        // который ставит человек, а не бот. Отсюда и условие: не признак
        // на ответе бота, а метка на реплике оператора.
        $operator = fn (Builder $q): Builder => $q->where('meta->to_kb', true);

        return match ($signal) {
            KbGapQuestion::SIGNAL_RATED_DOWN => $ratedDown($query),
            KbGapQuestion::SIGNAL_KB_MISS => $kbMiss($query),
            KbGapQuestion::SIGNAL_ESCALATED => $escalated($query),
            KbGapQuestion::SIGNAL_OPERATOR_ANSWER => $operator($query),
            default => $query
                ->where('rating', '<', 0)
                ->orWhere('kb_miss', true)
                ->orWhere('meta->escalated', true)
                ->orWhere('meta->to_kb', true),
        };
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return list<KbGapQuestion>
     */
    private function buildQuestions(Collection $messages): array
    {
        $asked = $this->visitorMessages($messages->pluck('chat_conversation_id')->unique()->all());

        $questions = [];

        foreach ($messages as $message) {
            $searchQuery = $this->searchQuery($message);
            $askedMessage = $this->questionMessage($asked, $message);
            $text = $askedMessage?->body ?? $searchQuery;

            if ($text === null || trim($text) === '') {
                // Ответ без вопроса — служебная заглушка или обрезанная
                // переписка. Показывать в очереди работ нечего.
                continue;
            }

            $questions[] = new KbGapQuestion(
                messageId: (int) $message->getKey(),
                conversationId: (int) $message->chat_conversation_id,
                text: $text,
                searchQuery: $searchQuery,
                askedAt: $message->created_at,
                signals: $this->signalsOf($message),
                vector: $this->vectorOf($message),
                questionMessageId: $askedMessage === null ? null : (int) $askedMessage->getKey(),
                // Материал: то, что менеджер уже ответил живому покупателю.
                answer: $message->role === ChatMessage::ROLE_OPERATOR ? trim((string) $message->body) : null,
            );
        }

        return $questions;
    }

    /**
     * Реплики покупателей во всех затронутых диалогах.
     *
     * Одним запросом на весь отчёт, а не коррелированным подзапросом
     * на каждый ответ: подзапрос к той же таблице требует алиаса на внешнем
     * запросе, иначе MySQL связывает `chat_messages.id` с внутренней копией
     * таблицы и молча возвращает не то. Диалог — это десяток реплик,
     * выборка по нескольким сотням диалогов ничего не стоит.
     *
     * @param  list<int>  $conversationIds
     * @return Collection<int, Collection<int, ChatMessage>> ключ — диалог, внутри по возрастанию id
     */
    private function visitorMessages(array $conversationIds): Collection
    {
        return ChatMessage::query()
            ->whereIn('chat_conversation_id', $conversationIds)
            ->where('role', ChatMessage::ROLE_VISITOR)
            ->orderBy('id')
            ->get(['id', 'chat_conversation_id', 'body'])
            ->groupBy('chat_conversation_id');
    }

    /**
     * Реплика, вызвавшая этот ответ, — последняя перед ним.
     *
     * Возвращается сама модель, а не текст: её id — то, по чему мост
     * «Ответить в базу знаний» подставляет вопрос в форму статьи.
     *
     * @param  Collection<int, Collection<int, ChatMessage>>  $asked
     */
    private function questionMessage(Collection $asked, ChatMessage $message): ?ChatMessage
    {
        return $asked->get($message->chat_conversation_id, collect())
            ->last(fn (ChatMessage $visitor): bool => $visitor->getKey() < $message->getKey()
                && is_string($visitor->body));
    }

    /** С каким запросом бот пошёл в базу знаний — первый вызов за ход. */
    private function searchQuery(ChatMessage $message): ?string
    {
        foreach ($message->toolCallsDetailed() as $call) {
            if ($call['name'] !== 'search_knowledge_base') {
                continue;
            }

            $query = $call['arguments']['query'] ?? null;

            return is_string($query) && trim($query) !== '' ? trim($query) : null;
        }

        return null;
    }

    /** @return list<string> */
    private function signalsOf(ChatMessage $message): array
    {
        $meta = (array) ($message->meta ?? []);

        // Ответ менеджера попадает сюда только помеченным — значит сигнал
        // у него ровно один, и остальные проверки к нему неприменимы:
        // оценки у операторских реплик нет, в базу знаний они не ходят.
        if ($message->role === ChatMessage::ROLE_OPERATOR) {
            return [KbGapQuestion::SIGNAL_OPERATOR_ANSWER];
        }

        $signals = [];

        if ((int) $message->rating < 0) {
            $signals[] = KbGapQuestion::SIGNAL_RATED_DOWN;
        }

        // Только escalate_to_operator — почему не request_contact,
        // разобрано в applySignals().
        if ($meta['escalated'] ?? false) {
            $signals[] = KbGapQuestion::SIGNAL_ESCALATED;
        }

        if ((bool) $message->kb_miss) {
            $signals[] = KbGapQuestion::SIGNAL_KB_MISS;
        }

        return $signals;
    }

    /**
     * @return list<float>
     */
    private function vectorOf(ChatMessage $message): array
    {
        $blob = $message->getAttribute('embedding');

        // Битый BLOB роняет unpack исключением. Отчёт из-за одной кривой
        // строки падать не должен — такой вопрос просто не сгруппируется.
        if (! is_string($blob) || $blob === '' || strlen($blob) % 4 !== 0) {
            return [];
        }

        return KbVectorStore::unpackVector($blob);
    }
}
