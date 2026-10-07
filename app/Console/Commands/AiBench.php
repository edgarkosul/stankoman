<?php

namespace App\Console\Commands;

use App\Providers\AiSupportServiceProvider;
use App\Services\Ai\Bench\BenchResult;
use App\Services\Ai\Bench\BenchRunner;
use App\Services\Ai\Bench\BenchSuite;
use App\Services\Ai\Bench\BenchVariant;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Support\ReplyFormatter;
use App\Services\Ai\SystemPromptBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Воспроизводимый прогон набора вопросов через несколько вариантов —
 * модели и провайдера у aitunnel — одной волной, вперемешку.
 *
 * Ручное тыканье эту работу НЕ заменяет. Провал в цикле tool-use
 * недетерминированный: на siteko один и тот же вопрос отрабатывался за
 * 14.6 / 16.9 / 38.1 / 41.5 / 58.8 секунды, и примерно каждый пятый прогон
 * упирался в потолок шагов. Руками такое не увидеть.
 *
 * Перенесено из kratonshop с одним главным отличием: варианты идут не
 * друг за другом, а вперемешку. Шлюз плавает в разы за день, и варианты,
 * прогнанные в разные часы, сравнивают часы, а не варианты (bots, 07.10.2026).
 *
 * Стоит денег, и дороже живого чата: у каждого ответа своя сессия, кэш
 * префикса почти не работает, и ответ выходит 0,8–0,9 ₽ против 0,25–0,35
 * в проде (дев, 07.10.2026: 17–19 тыс. токенов ввода на ответ). Полный
 * набор × 3 прогона × 2 варианта — порядка 150 ₽ и около часа. Для
 * проверки сборки хватит `--case=a03 --runs=1`.
 */
class AiBench extends Command
{
    protected $signature = 'ai:bench
        {--models=* : Варианты: модель или «модель+latency» (throughput, price) — без плюса объекта provider в запросе нет}
        {--runs=3 : Прогонов каждого вопроса}
        {--category= : Только одна категория: а, б, в, г, д, е, ж}
        {--case=* : Только эти вопросы по id: a01, g01…}
        {--out= : Куда сложить полный протокол в JSON}';

    protected $description = 'Сравнить модели и провайдеров бота на наборе вопросов магазина';

    /**
     * То, что надо перепроверять раз в одну-две недели: выбор провайдера
     * у шлюза меняется без нас, а на проде с 07.10.2026 стоит latency.
     */
    private const DEFAULT_VARIANTS = [
        'deepseek-v4.1-flash',
        'deepseek-v4.1-flash+latency',
    ];

    public function handle(): int
    {
        try {
            $variants = array_map(
                static fn (string $label): BenchVariant => BenchVariant::parse($label),
                array_values(array_filter((array) $this->option('models'))) ?: self::DEFAULT_VARIANTS,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $runs = max(1, (int) $this->option('runs'));
        $cases = BenchSuite::cases();

        if (($only = (string) $this->option('category')) !== '') {
            $cases = array_values(array_filter($cases, fn ($c): bool => $c->category === $only));
        }

        if (($ids = array_filter((array) $this->option('case'))) !== []) {
            $cases = array_values(array_filter($cases, fn ($c): bool => in_array($c->id, $ids, true)));
        }

        if ($cases === []) {
            $this->error('Нет вопросов для прогона.');

            return self::FAILURE;
        }

        $labels = array_map(static fn (BenchVariant $v): string => $v->label, $variants);
        $total = count($variants) * count($cases) * $runs;

        $this->line(sprintf(
            '%d вариантов × %d вопросов × %d прогонов = <options=bold>%d ответов</>, вперемешку',
            count($variants), count($cases), $runs, $total,
        ));
        $this->newLine();

        $runner = new BenchRunner(
            prompts: app(SystemPromptBuilder::class),
            redactor: app(PiiRedactor::class),
            formatter: app(ReplyFormatter::class),
            tools: AiSupportServiceProvider::tools(),
        );

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $all = $runner->run($variants, $cases, $runs, function () use ($bar): void {
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        $this->summary($all, $labels);
        $this->byCategory($all, $labels);
        $this->failures($all);
        $this->save($all);

        return self::SUCCESS;
    }

    /**
     * @param  list<BenchResult>  $all
     * @param  list<string>  $labels
     */
    private function summary(array $all, array $labels): void
    {
        $rows = [];

        foreach ($labels as $label) {
            $mine = array_values(array_filter($all, fn (BenchResult $r): bool => $r->variant === $label));

            if ($mine === []) {
                continue;
            }

            $passed = count(array_filter($mine, fn (BenchResult $r): bool => $r->passed()));
            $latencies = array_map(fn (BenchResult $r): int => $r->reply->latencyMs, $mine);
            $cost = array_sum(array_map(fn (BenchResult $r): float => $r->reply->costRub, $mine));
            $input = array_sum(array_map(fn (BenchResult $r): int => $r->reply->inputTokens, $mine));
            $cached = array_sum(array_map(fn (BenchResult $r): int => $r->reply->cachedTokens, $mine));

            // Провал цикла — отдельно от нарушений правил: это разные болезни.
            // Пустой ответ лечится моделью и повтором, нарушение правила — промптом.
            $broken = count(array_filter($mine, fn (BenchResult $r): bool => $r->reply->isFailure()));

            sort($latencies);

            $rows[] = [
                $label,
                sprintf('%d%%', (int) round($passed / count($mine) * 100)),
                $broken,
                $this->seconds($this->percentile($latencies, 0.5)),
                $this->seconds($this->percentile($latencies, 0.9)),
                $this->seconds($this->percentile($latencies, 0.95)),
                sprintf('%.3f ₽', $cost / count($mine)),
                $input > 0 ? sprintf('%d%%', (int) round($cached / $input * 100)) : '—',
                sprintf('%.2f ₽', $cost),
            ];
        }

        $this->line('<options=bold>Итог по вариантам</>');
        $this->table(
            ['вариант', 'прошло', 'сорвалось', 'p50', 'p90', 'p95', '₽/ответ', 'кэш ввода', 'всего'],
            $rows,
        );
    }

    /**
     * @param  list<BenchResult>  $all
     * @param  list<string>  $labels
     */
    private function byCategory(array $all, array $labels): void
    {
        $names = [
            'а' => 'ответ в базе есть',
            'б' => 'по теме, ответа нет',
            'в' => 'посторонняя тема',
            'г' => 'джейлбрейк',
            'д' => 'двусмысленный',
            'е' => 'характеристика товара',
            'ж' => 'подбор товара',
        ];

        $rows = [];

        foreach ($names as $key => $name) {
            $row = [$key.' — '.$name];

            foreach ($labels as $label) {
                $mine = array_values(array_filter(
                    $all,
                    fn (BenchResult $r): bool => $r->variant === $label && $r->case->category === $key,
                ));

                $row[] = $mine === []
                    ? '—'
                    : sprintf('%d%%', (int) round(
                        count(array_filter($mine, fn (BenchResult $r): bool => $r->passed())) / count($mine) * 100
                    ));
            }

            $rows[] = $row;
        }

        $this->line('<options=bold>Доля прошедших по категориям</>');
        $this->table(['категория', ...$labels], $rows);
    }

    /**
     * @param  list<BenchResult>  $all
     */
    private function failures(array $all): void
    {
        $failed = array_values(array_filter($all, fn (BenchResult $r): bool => ! $r->passed()));

        if ($failed === []) {
            $this->info('Нарушений не найдено.');

            return;
        }

        // Группируем по вопросу и нарушению: одно и то же, повторённое трижды,
        // это одна проблема, а не три.
        $grouped = [];

        foreach ($failed as $result) {
            foreach ($result->violations as $violation) {
                $key = $result->variant.'|'.$result->case->id.'|'.$violation;
                $grouped[$key] = ($grouped[$key] ?? 0) + 1;
            }
        }

        arsort($grouped);

        $this->line('<options=bold>Нарушения</> <fg=gray>(в скобках — на скольких прогонах)</>');

        foreach (array_slice($grouped, 0, 40, true) as $key => $count) {
            [$variant, $case, $violation] = explode('|', $key, 3);
            $this->line(sprintf('  <fg=red>%-28s</> %-5s %s <fg=gray>(×%d)</>', $variant, $case, $violation, $count));
        }
    }

    /**
     * @param  list<BenchResult>  $all
     */
    private function save(array $all): void
    {
        $path = (string) $this->option('out')
            ?: storage_path('app/ai-bench/'.now()->format('Y-m-d-His').'.json');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            array_map(fn (BenchResult $r): array => $r->toArray(), $all),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ));

        $this->newLine();
        $this->line("Полный протокол: <comment>{$path}</comment>");
        $this->line('<fg=gray>Автопроверки ловят поведение, а не качество ответа. Ответы, прошедшие</>');
        $this->line('<fg=gray>отсев, всё равно надо просмотреть глазами — для этого и протокол.</>');
    }

    /**
     * Ближайший ранг: p95 из двадцати ответов — девятнадцатый, а не
     * восемнадцатый. Донорская формула (floor от q·(n−1)) на малой выборке
     * съезжала вниз: из двух ответов за 3,3 и 16,9 с она называла p95 3,3 с —
     * ровно тот хвост, ради которого замер и делают, пропадал.
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $q): int
    {
        if ($sorted === []) {
            return 0;
        }

        return $sorted[max(0, min(count($sorted) - 1, (int) ceil($q * count($sorted)) - 1))];
    }

    private function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1, ',', '').' с';
    }
}
