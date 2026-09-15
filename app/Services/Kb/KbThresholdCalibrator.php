<?php

namespace App\Services\Kb;

/**
 * Подбор порога релевантности базы знаний по размеченным вопросам.
 *
 * Порог отделяет «нашёл ответ» от «в базе этого нет». Ошибка в каждую
 * сторону считается одной штукой: пропущенный ответ (бот зовёт менеджера,
 * хотя ответ в базе был) и прошедший шум (посторонний вопрос выглядит
 * найденным). Вопросы «по теме, но ответа нет» в подбор не входят: у донора
 * их оценки почти целиком перекрывались с отвеченными, и отделить их порогом
 * нельзя — их ловят оценки покупателей и экран «Пробелы».
 *
 * Из порогов с наименьшим числом ошибок берётся середина самого длинного
 * сплошного участка — наибольшее расстояние до обоих обрывов. Замер на трёх
 * десятках вопросов грубый, и порог у края участка сломался бы на первом же
 * новом вопросе.
 */
final class KbThresholdCalibrator
{
    /**
     * Ошибки на каждом пороге с шагом 0.01. Оценка, равная порогу, — найдено.
     *
     * @param  list<float>  $answered  лучшие оценки вопросов, ответ на которые в базе есть
     * @param  list<float>  $offTopic  лучшие оценки посторонних вопросов
     * @return list<array{threshold: float, missed: int, leaked: int}>
     */
    public function sweep(array $answered, array $offTopic, float $from = 0.20, float $to = 0.80): array
    {
        $rows = [];

        // Сотые целыми числами: шаг 0.01 во float за полсотни итераций уплывает.
        for ($cents = (int) round($from * 100); $cents <= (int) round($to * 100); $cents++) {
            $threshold = $cents / 100;

            $rows[] = [
                'threshold' => $threshold,
                'missed' => count(array_filter($answered, static fn (float $score): bool => $score < $threshold)),
                'leaked' => count(array_filter($offTopic, static fn (float $score): bool => $score >= $threshold)),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<float>  $answered
     * @param  list<float>  $offTopic
     * @return array{threshold: float, missed: int, leaked: int}
     */
    public function recommend(array $answered, array $offTopic): array
    {
        $rows = $this->sweep($answered, $offTopic);
        $fewest = min(array_map(static fn (array $row): int => $row['missed'] + $row['leaked'], $rows));

        $longest = [];
        $current = [];

        foreach ($rows as $row) {
            if ($fewest !== $row['missed'] + $row['leaked']) {
                $current = [];

                continue;
            }

            $current[] = $row;

            if (count($current) > count($longest)) {
                $longest = $current;
            }
        }

        return $longest[intdiv(count($longest) - 1, 2)];
    }
}
