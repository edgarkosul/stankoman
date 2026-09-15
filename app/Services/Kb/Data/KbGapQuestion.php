<?php

namespace App\Services\Kb\Data;

use Carbon\CarbonInterface;

/**
 * Один вопрос покупателя, на который бот ответил плохо, — сырьё для экрана
 * «Пробелы в базе знаний».
 *
 * Сигналы НЕ взаимозаменяемы (замер донора 28.08.2026):
 *
 *   - `rated_down` — прямая оценка покупателя. Самый дорогой сигнал:
 *     до кнопки 👎 доходят единицы, и каждый такой вопрос стоит прочитать
 *     глазами, даже если он единственный в своей группе;
 *   - `escalated` — бот сам позвал человека. Признание «ответа у меня нет»,
 *     сделанное самим ботом, и потому сигнал сильный;
 *   - `kb_miss` — поиск не набрал порога. Сигнал СЛАБЫЙ: и у донора, и на
 *     своей калибровке (15.09.2026, 6 из 10 вопросов без ответа выше порога)
 *     по нему не отличить дыру в базе от вопроса не по адресу. Годится как
 *     вход, но не как вывод;
 *   - `operator_answer` — менеджер ответил сам и пометил свой ответ «в базу».
 *     Первые три оставляет БОТ, и в разговоре, который ведёт человек, не
 *     срабатывает ни один: очередь работ была бы пуста ровно там, где
 *     материала больше всего. Этот сигнал ставит человек, и с ним приезжает
 *     то, чего у остальных нет, — готовый ответ магазина.
 *
 * Чего здесь намеренно нет — `FAILED_STOP_REASONS` («бот не справился»).
 * Упавший шлюз, кончившиеся шаги и неподнятый воркер — работа разработчика,
 * а не того, кто пишет базу знаний; в «Диалогах» для них свой фильтр.
 */
final readonly class KbGapQuestion
{
    public const SIGNAL_RATED_DOWN = 'rated_down';

    public const SIGNAL_ESCALATED = 'escalated';

    public const SIGNAL_KB_MISS = 'kb_miss';

    /** Менеджер ответил сам и отправил свой ответ в базу знаний. */
    public const SIGNAL_OPERATOR_ANSWER = 'operator_answer';

    /**
     * @param  int  $messageId  ответ бота или менеджера — на нём сигналы и вектор
     * @param  string  $text  реплика покупателя, вызвавшая этот ответ
     * @param  string|null  $searchQuery  с чем бот пошёл в базу знаний
     * @param  list<string>  $signals  чем плох ответ — константы SIGNAL_*
     * @param  list<float>  $vector  вектор вопроса; пустой, если посчитать не удалось
     * @param  int|null  $questionMessageId  сама реплика покупателя; null, когда
     *                                       текст восстановлен из запроса к базе,
     *                                       а не взят из переписки
     * @param  string|null  $answer  ответ менеджера, отмеченный «в базу». Есть
     *                               только у сигнала `operator_answer` и в этом
     *                               его ценность: остальные сигналы говорят,
     *                               ЧЕГО в базе нет, а этот приносит ещё и то,
     *                               что там должно появиться
     */
    public function __construct(
        public int $messageId,
        public int $conversationId,
        public string $text,
        public ?string $searchQuery,
        // Интерфейс, а не класс: приложение работает на неизменяемых датах,
        // а тесты группировки собирают вопросы из обычных.
        public CarbonInterface $askedAt,
        public array $signals,
        public array $vector = [],
        public ?int $questionMessageId = null,
        public ?string $answer = null,
    ) {}

    public function hasSignal(string $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    /**
     * Есть ли по чему группировать.
     *
     * Вектор вопроса считает джоба ответа от слов покупателя, на каждый ход.
     * Пустым он остаётся, только когда шлюз эмбеддингов в тот момент лежал,
     * — ответ покупателю из-за этого не страдает, а вопрос уходит в список
     * «без группировки».
     */
    public function isClusterable(): bool
    {
        return $this->vector !== [];
    }

    /** Короткая подпись для списка: вопрос в одну строку. */
    public function preview(int $limit = 160): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->text) ?? '');

        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit - 1).'…'
            : $text;
    }
}
