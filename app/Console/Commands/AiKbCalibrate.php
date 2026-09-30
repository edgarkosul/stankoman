<?php

namespace App\Console\Commands;

use App\Services\Kb\KbThresholdCalibrator;
use App\Services\Kb\KbVectorStore;
use App\Shop\KbCalibrationQuestions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Замер порога релевантности базы знаний на размеченных вопросах.
 *
 * Порог `ai_support.knowledge_base.min_score` сначала достался от kratonshop,
 * где его мерили на других страницах, и на нашей базе он пропускал посторонние
 * вопросы: оценки близости зависят от корпуса, а короткий фрагмент из одних
 * цифр притягивает что угодно. Поэтому порог меряется здесь, на настоящих
 * фрагментах и настоящем эмбеддере, — и заново после каждой заметной правки
 * базы. Результат замера и почему выбрано именно это число — в конфиге.
 *
 * Ходит в шлюз (по вызову на вопрос, из кэша — бесплатно), в базу не пишет.
 */
class AiKbCalibrate extends Command
{
    protected $signature = 'ai:kb-calibrate';

    protected $description = 'Замерить порог релевантности базы знаний на размеченных вопросах';

    public function handle(KbVectorStore $store, KbThresholdCalibrator $calibrator): int
    {
        $table = (string) config('ai_support.knowledge_base.table');

        if (DB::table($table)->whereNotNull('embedding')->doesntExist()) {
            $this->error('База знаний пуста — мерить не на чем. Сначала: php artisan ai:kb-reindex');

            return self::FAILURE;
        }

        $scores = [];
        $rows = [];

        foreach (KbCalibrationQuestions::all() as $group => $questions) {
            foreach ($questions as $question) {
                $hit = $store->search($question, topK: 1)[0] ?? null;
                $score = $hit?->score ?? 0.0;

                $scores[$group][] = $score;
                $rows[] = [
                    KbCalibrationQuestions::LABELS[$group],
                    $question,
                    sprintf('%.3f', $score),
                    $hit !== null ? mb_strimwidth($hit->path(), 0, 70, '…') : '—',
                ];
            }
        }

        $this->table(['группа', 'вопрос', 'оценка', 'лучший фрагмент'], $rows);

        $this->table(['группа', 'мин', 'медиана', 'макс'], array_map(
            fn (string $group): array => [
                KbCalibrationQuestions::LABELS[$group],
                sprintf('%.3f', min($scores[$group] ?? [0.0])),
                sprintf('%.3f', $this->median($scores[$group] ?? [0.0])),
                sprintf('%.3f', max($scores[$group] ?? [0.0])),
            ],
            array_keys(KbCalibrationQuestions::LABELS),
        ));

        $answered = $scores[KbCalibrationQuestions::ANSWERED] ?? [];
        $offTopic = $scores[KbCalibrationQuestions::OFF_TOPIC] ?? [];
        $unanswered = $scores[KbCalibrationQuestions::UNANSWERED] ?? [];

        $currentThreshold = (float) config('ai_support.knowledge_base.min_score');
        $current = $calibrator->sweep($answered, $offTopic, $currentThreshold, $currentThreshold)[0];
        $recommended = $calibrator->recommend($answered, $offTopic);

        $this->line(sprintf(
            'Текущий порог %.2f: пропущено ответов %d из %d, посторонних прошло %d из %d.',
            $current['threshold'], $current['missed'], count($answered), $current['leaked'], count($offTopic),
        ));
        $this->info(sprintf(
            'Рекомендуемый порог %.2f: пропущено ответов %d, посторонних прошло %d.',
            $recommended['threshold'], $recommended['missed'], $recommended['leaked'],
        ));

        // Не ошибка порога: вопрос по теме, ответа нет, а близкий фрагмент есть.
        // Такие ловят оценки покупателей и экран «Пробелы», а не порог.
        $this->line(sprintf(
            'Вопросов без ответа выше рекомендуемого порога: %d из %d — бот сочтёт их найденными.',
            count(array_filter($unanswered, static fn (float $score): bool => $score >= $recommended['threshold'])),
            count($unanswered),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
