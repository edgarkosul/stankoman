<?php

namespace App\Services\Kb;

use App\Services\Kb\Data\KbGapCluster;
use App\Services\Kb\Data\KbGapQuestion;

/**
 * Группировка вопросов по смыслу — на векторах, которые уже лежат в базе.
 *
 * Ни одного обращения к шлюзу: вектор вопроса посчитан в момент ответа,
 * от слов покупателя, и сохранён рядом с ответом (`chat_messages.embedding`).
 * Платить за него второй раз ради отчёта незачем — тем более что вопросов
 * с сигналом накапливаются сотни.
 *
 * Алгоритм — «лидер»: идём по вопросам от старых к новым, каждый либо
 * попадает в группу, с центром которой он достаточно схож, либо заводит
 * свою. K-средних здесь не нужен и вреден: он требует заранее знать число
 * групп, а мы его как раз и выясняем — сегодня спрашивают про возврат,
 * завтра про счёт на организацию.
 *
 * Векторы нормированы (и на вставке, и здесь при усреднении), поэтому
 * косинус — это скалярное произведение без деления на длины.
 */
final class KbGapClusterer
{
    public function __construct(
        /**
         * Насколько похожими должны быть вопросы, чтобы считаться одним.
         * Значение подбирается на живых вопросах, а не выводится из теории:
         * слишком высокое рассыпает группы на синонимы, слишком низкое
         * склеивает «доставка в Крым» с «доставка транспортной компанией».
         */
        private readonly float $threshold,
    ) {}

    /**
     * @param  list<KbGapQuestion>  $questions
     * @return list<KbGapCluster>
     */
    public function cluster(array $questions): array
    {
        $questions = array_values(array_filter(
            $questions,
            static fn (KbGapQuestion $q): bool => $q->isClusterable(),
        ));

        /*
         * От старых к новым — чтобы порядок групп не менялся от того,
         * что кто-то задал вопрос минуту назад. Лидером становится первый
         * задавший, а не последний.
         */
        usort($questions, static fn (KbGapQuestion $a, KbGapQuestion $b): int => [$a->askedAt->getTimestamp(), $a->messageId] <=> [$b->askedAt->getTimestamp(), $b->messageId]);

        /** @var list<array{sum: list<float>, centroid: list<float>, items: list<KbGapQuestion>}> $groups */
        $groups = [];

        foreach ($questions as $question) {
            $best = null;
            $bestScore = $this->threshold;

            foreach ($groups as $index => $group) {
                // Вектор от другой модели или размерности сравнивать не с чем.
                // Такой вопрос заведёт свою группу — это честнее, чем сложить
                // его с чужими по бессмысленному числу.
                if (count($group['centroid']) !== count($question->vector)) {
                    continue;
                }

                $score = self::dot($question->vector, $group['centroid']);

                if ($score >= $bestScore) {
                    $bestScore = $score;
                    $best = $index;
                }
            }

            if ($best === null) {
                $groups[] = [
                    'sum' => $question->vector,
                    'centroid' => $question->vector,
                    'items' => [$question],
                ];

                continue;
            }

            $sum = $groups[$best]['sum'];

            foreach ($question->vector as $i => $value) {
                $sum[$i] += $value;
            }

            $groups[$best]['sum'] = $sum;
            $groups[$best]['centroid'] = self::normalize($sum);
            $groups[$best]['items'][] = $question;
        }

        $clusters = array_map(
            static fn (array $group): KbGapCluster => KbGapCluster::make($group['items'], $group['centroid']),
            $groups,
        );

        /*
         * Порядок — очередь работ: сначала то, о чём спрашивают чаще,
         * при равной частоте — то, о чём спрашивали недавно. Одиночные
         * вопросы оказываются внизу сами собой, но из списка не выпадают:
         * единственное 👎 может стоить десяти kb_miss.
         */
        usort($clusters, static fn (KbGapCluster $a, KbGapCluster $b): int => [$b->count(), $b->lastAskedAt()->getTimestamp()] <=> [$a->count(), $a->lastAskedAt()->getTimestamp()]);

        return array_values($clusters);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;

        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private static function normalize(array $vector): array
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        $norm = sqrt($sum);

        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }
}
