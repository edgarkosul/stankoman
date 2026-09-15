<?php

namespace App\Services\Kb\Data;

/**
 * Итог разбора «Пробелов»: что сгруппировалось, что нет и всё ли влезло.
 */
final readonly class KbGapReport
{
    /**
     * @param  list<KbGapCluster>  $clusters
     * @param  list<KbGapQuestion>  $ungrouped  вопросы без вектора — группировать нечем
     * @param  bool  $truncated  сигналов было больше, чем показано: взяты самые свежие
     */
    public function __construct(
        public array $clusters = [],
        public array $ungrouped = [],
        public bool $truncated = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->clusters === [] && $this->ungrouped === [];
    }

    /** Сколько всего вопросов разобрано — и сгруппированных, и одиночек. */
    public function questionsCount(): int
    {
        $count = count($this->ungrouped);

        foreach ($this->clusters as $cluster) {
            $count += $cluster->count();
        }

        return $count;
    }

    /**
     * Сигналы по всему отчёту — шапка экрана.
     *
     * @return array<string, int>
     */
    public function signalCounts(): array
    {
        $counts = [];

        foreach ($this->clusters as $cluster) {
            foreach ($cluster->signalCounts() as $signal => $number) {
                $counts[$signal] = ($counts[$signal] ?? 0) + $number;
            }
        }

        foreach ($this->ungrouped as $question) {
            foreach ($question->signals as $signal) {
                $counts[$signal] = ($counts[$signal] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
