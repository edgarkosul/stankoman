<?php

use App\Services\Kb\KbChunker;
use Tests\TestCase;

uses(TestCase::class);

$chunk = fn (string $markdown, int $max = KbChunker::MAX_CHARS): array => (new KbChunker)
    ->chunk('intertooler-page:demo', 'Демо', ['InterTooler.ru', 'Демо'], $markdown, $max);

it('режет по заголовкам второго уровня', function () use ($chunk): void {
    $chunks = $chunk("## Оплата\n\nПлатите как удобно.\n\n## Доставка\n\nВезём быстро.");

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]['section_path'])->toBe(['Оплата'])
        ->and($chunks[1]['section_path'])->toBe(['Доставка'])
        ->and($chunks[1]['text'])->toContain('Везём быстро.');
});

it('подставляет крошки префиксом — именно они уходят в эмбеддинг', function () use ($chunk): void {
    $chunks = $chunk("## Оплата юрлицом\n\nСчёт с расчётного счёта.");

    // Без префикса фрагмент «Счёт с расчётного счёта» не отвечает на вопрос
    // «как платит организация»: связь даёт только путь раздела.
    expect($chunks[0]['text'])->toStartWith('InterTooler.ru > Демо > Оплата юрлицом');
});

it('выбрасывает H1, потому что заголовок страницы уже есть в крошках', function () use ($chunk): void {
    $chunks = $chunk("# Способы оплаты\n\nВступление.\n\n## Наличными\n\nВ офисе.");

    expect($chunks[0]['text'])->not->toContain('# Способы оплаты')
        ->and($chunks[0]['text'])->toContain('Вступление.');
});

it('дорезает крупный раздел по подзаголовкам', function () use ($chunk): void {
    $long = str_repeat('Длинный текст про доставку. ', 30);
    $chunks = $chunk("## Доставка\n\n### По городу\n\n{$long}\n\n### По России\n\n{$long}", 200);

    // Подзаголовок обязан попасть в путь. Дальше кусок может делиться ещё
    // и на части — сравниваем начало пути, а не путь целиком.
    $heads = array_map(
        static fn (array $c): array => array_slice($c['section_path'], 0, 2),
        $chunks,
    );

    expect($heads)->toContain(['Доставка', 'По городу'])
        ->and($heads)->toContain(['Доставка', 'По России']);
});

it('делит по абзацам, когда подзаголовков нет, и нумерует части', function () use ($chunk): void {
    $paragraph = str_repeat('Текст. ', 40);
    $chunks = $chunk("## Раздел\n\n{$paragraph}\n\n{$paragraph}\n\n{$paragraph}", 200);

    expect(count($chunks))->toBeGreaterThan(1)
        ->and($chunks[0]['section_path'])->toBe(['Раздел', 'ч. 1'])
        ->and($chunks[1]['section_path'])->toBe(['Раздел', 'ч. 2']);
});

it('не выпускает фрагмент длиннее порога даже из одного сплошного предложения', function () use ($chunk): void {
    $chunks = $chunk('## Раздел'."\n\n".str_repeat('а', 900), 200);

    foreach ($chunks as $piece) {
        expect($piece['chars'])->toBeLessThanOrEqual(200);
    }
});

it('даёт устойчивые chunk_id от локатора, а не от адреса страницы', function () use ($chunk): void {
    $chunks = $chunk("## Раз\n\nТекст.\n\n## Два\n\nТекст.");

    expect(array_column($chunks, 'chunk_id'))
        ->toBe(['intertooler-page:demo#0', 'intertooler-page:demo#1']);
});

it('на пустом тексте не выпускает фрагментов', function () use ($chunk): void {
    expect($chunk("\n\n   \n"))->toBe([]);
});
