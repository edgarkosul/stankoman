<?php

namespace App\Services\Kb\Data;

use Carbon\CarbonInterface;

/**
 * Группа вопросов об одном и том же — строчка очереди работ.
 *
 * Смысл экрана «Пробелы» именно в группировке: триста отдельных строк
 * это не список задач, а лента, которую нельзя прочитать. «Про возврат
 * спрашивали 14 раз» — задача, «можно ли вернуть товар через год» —
 * одна из её формулировок.
 */
final readonly class KbGapCluster
{
    /**
     * С какого числа повторов группа считается заслуживающей статьи.
     *
     * Три — решение, принятое у донора 08.09.2026. Порог НЕ прячет ничего:
     * он только помечает. Прятать было бы ошибкой: до кнопки 👎 доходят
     * единицы, и такой вопрос стоит прочитать глазами, даже если он в группе
     * один.
     */
    public const WORTH_ARTICLE = 3;

    /**
     * @param  list<KbGapQuestion>  $questions  от самой типичной формулировки к прочим
     * @param  list<float>  $centroid  среднее вопросов группы, нормированное
     */
    private function __construct(
        public array $questions,
        public array $centroid,
    ) {}

    /**
     * @param  list<KbGapQuestion>  $questions
     * @param  list<float>  $centroid
     */
    public static function make(array $questions, array $centroid): self
    {
        /*
         * Первым идёт вопрос, ближайший к центру группы, — самая типичная
         * её формулировка. Заголовком группы становится он, и это лучше
         * и первого по времени (случайная фраза), и самого частого
         * (одинаковых формулировок у живых людей не бывает).
         */
        usort($questions, static function (KbGapQuestion $a, KbGapQuestion $b) use ($centroid): int {
            $byCenter = self::dot($b->vector, $centroid) <=> self::dot($a->vector, $centroid);

            // Второй ключ — время: без него порядок внутри группы
            // с одинаковыми векторами плавал бы от запроса к запросу.
            return $byCenter !== 0 ? $byCenter : $b->askedAt->getTimestamp() <=> $a->askedAt->getTimestamp();
        });

        return new self(array_values($questions), $centroid);
    }

    public function title(): string
    {
        return $this->questions[0]->preview();
    }

    public function worthArticle(): bool
    {
        return $this->count() >= self::WORTH_ARTICLE;
    }

    public function count(): int
    {
        return count($this->questions);
    }

    /**
     * Сколько раз какой сигнал сработал в группе. Один вопрос может дать
     * сразу два: бот не нашёл ответа, позвал человека — и получил 👎.
     *
     * @return array<string, int>
     */
    public function signalCounts(): array
    {
        $counts = [];

        foreach ($this->questions as $question) {
            foreach ($question->signals as $signal) {
                $counts[$signal] = ($counts[$signal] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function lastAskedAt(): CarbonInterface
    {
        return collect($this->questions)->max(fn (KbGapQuestion $q): CarbonInterface => $q->askedAt);
    }

    /**
     * Ответы менеджеров группы — материал будущей статьи.
     *
     * @return list<string>
     */
    public function answers(): array
    {
        return array_values(array_filter(array_map(
            static fn (KbGapQuestion $q): ?string => $q->answer,
            $this->questions,
        )));
    }

    /**
     * Реплики покупателей группы — то, из чего пишется статья.
     *
     * Порядок сохранён: первой идёт самая типичная формулировка, она же
     * становится заголовком будущей статьи. Вопросы, чей текст восстановлен
     * из запроса к базе, а не взят из переписки, сюда не попадают —
     * подставлять в форму нечего.
     *
     * @return list<int>
     */
    public function questionMessageIds(int $limit = 12): array
    {
        $ids = [];

        foreach ($this->questions as $question) {
            if ($question->questionMessageId === null || in_array($question->questionMessageId, $ids, true)) {
                continue;
            }

            $ids[] = $question->questionMessageId;

            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /** @return list<int> */
    public function conversationIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (KbGapQuestion $q): int => $q->conversationId,
            $this->questions,
        )));
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $i => $value) {
            $sum += $value * ($b[$i] ?? 0.0);
        }

        return $sum;
    }
}
