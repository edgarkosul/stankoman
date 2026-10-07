<?php

use App\Services\Ai\Bench\BenchCase;
use App\Services\Ai\Bench\BenchRunner;
use App\Services\Ai\Bench\BenchSuite;
use App\Services\Ai\Bench\BenchVariant;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\AssistantReply;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Support\ReplyFormatter;
use App\Services\Ai\SystemPromptBuilder;
use Tests\TestCase;

// Агент пишет в лог на провалах — нужен поднятый контейнер.
uses(TestCase::class);

it('разбирает вариант на модель и провайдера', function (): void {
    $plain = BenchVariant::parse('deepseek-v4.1-flash');
    $fast = BenchVariant::parse('deepseek-v4.1-flash+latency');

    // Без плюса объекта provider нет вовсе, что бы ни стояло в настройке:
    // иначе «с latency против без» на проде сравнивал бы latency с собой.
    expect([$plain->model, $plain->providerSort])->toBe(['deepseek-v4.1-flash', ''])
        ->and([$fast->model, $fast->providerSort])->toBe(['deepseek-v4.1-flash', 'latency']);
});

it('не принимает выдуманный способ выбора провайдера', function (): void {
    BenchVariant::parse('deepseek-v4.1-flash+fastest');
})->throws(InvalidArgumentException::class);

it('первым идёт то один вариант, то другой', function (): void {
    expect(BenchRunner::order(['a', 'b'], run: 1, index: 0))->toBe(['a', 'b'])
        ->and(BenchRunner::order(['a', 'b'], run: 1, index: 1))->toBe(['b', 'a'])
        ->and(BenchRunner::order(['a', 'b'], run: 2, index: 0))->toBe(['b', 'a'])
        ->and(BenchRunner::order(['a', 'b', 'c'], run: 1, index: 2))->toBe(['c', 'a', 'b']);
});

it('гонит каждый вопрос через все варианты вперемешку и считает нарушения', function (): void {
    $calls = [];

    $client = static function (BenchVariant $variant) use (&$calls): LlmClient {
        return new class($variant->label, $calls) implements LlmClient
        {
            public function __construct(private string $label, private array &$calls) {}

            public function chat(string $system, array $messages, array $tools = [],
                ?int $maxTokens = null, ?string $sessionId = null, ?string $toolChoice = null): ChatResult
            {
                $this->calls[] = $this->label;

                return new ChatResult($this->label === 'b' ? 'Доставка 3-5 рабочих дней.' : 'Не знаю.', finishReason: 'stop');
            }

            public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
            {
                return new EmbeddingBatch([], 'fake', 4);
            }

            public function chatModel(): string
            {
                return $this->label;
            }

            public function embeddingModel(): string
            {
                return 'fake';
            }

            public function embeddingDimensions(): int
            {
                return 4;
            }
        };
    };

    $runner = new BenchRunner(new SystemPromptBuilder('InterTooler.ru'), new PiiRedactor, new ReplyFormatter, [], $client);

    $results = $runner->run(
        [BenchVariant::parse('a'), BenchVariant::parse('b')],
        [new BenchCase('x1', 'а', 'Когда отгрузите?', mustContain: ['рабоч']), new BenchCase('x2', 'а', 'А доставка?', mustContain: ['рабоч'])],
        runs: 1,
    );

    // Вопрос x1 — сначала a, x2 — сначала b.
    expect($calls)->toBe(['a', 'b', 'b', 'a'])
        ->and(array_map(fn ($r): string => $r->variant.':'.$r->case->id.':'.($r->passed() ? 'ok' : 'fail'), $results))
        ->toBe(['a:x1:fail', 'a:x2:fail', 'b:x1:ok', 'b:x2:ok'])
        ->and($results[2]->position)->toBe(2);
});

it('не путает «ё», регистр и тире с провалом', function (): void {
    $case = new BenchCase('x', 'а', 'вопрос', mustContain: ['счёт', '3–5']);

    expect($case->violations(new AssistantReply(text: 'Выставим СЧЕТ, отгрузка 3-5 дней.', stopReason: 'stop')))->toBe([]);
});

it('держит набор вопросов целым: id уникальны, категории известны', function (): void {
    $cases = BenchSuite::cases();
    $ids = array_map(fn (BenchCase $c): string => $c->id, $cases);

    expect($ids)->toBe(array_values(array_unique($ids)))
        ->and(array_diff(array_unique(array_map(fn (BenchCase $c): string => $c->category, $cases)), ['а', 'б', 'в', 'г', 'д', 'е', 'ж']))->toBe([]);
});

it('ловит выдуманное значение, а не честный отказ его назвать', function (): void {
    $case = collect(BenchSuite::cases())->firstWhere('id', 'f05');

    // Прод, 07.10.2026: такой отказ замер считал провалом из-за слова «дБ».
    $refusal = new AssistantReply(text: 'Уровень шума в карточке не указан, назвать значение в дБ я не могу.', stopReason: 'stop');
    $invented = new AssistantReply(text: 'Уровень шума — около 68 дБ.', stopReason: 'stop');

    expect($case->violations($refusal))->toBe([])
        ->and($case->violations($invented))->not->toBe([]);
});
