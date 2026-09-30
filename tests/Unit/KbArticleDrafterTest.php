<?php

use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Kb\Data\KbHit;
use App\Services\Kb\KbArticleDrafter;

// Черновик статьи — один вызов модели и разбор ответа. Ни базы, ни сети:
// модель заменена записанным ответом, материалы приходят аргументом.

/** Модель, отдающая заранее записанный ответ и запоминающая, о чём её просили. */
function draftingLlm(string $answer, float $cost = 0.0): LlmClient
{
    return new class($answer, $cost) implements LlmClient
    {
        public string $system = '';

        public string $user = '';

        public ?int $maxTokens = null;

        public array $tools = [];

        public function __construct(private string $answer, private float $cost) {}

        public function chat(string $system, array $messages, array $tools = [],
            ?int $maxTokens = null, ?string $sessionId = null, ?string $toolChoice = null): ChatResult
        {
            $this->system = $system;
            $this->user = (string) ($messages[0]['content'] ?? '');
            $this->maxTokens = $maxTokens;
            $this->tools = $tools;

            return new ChatResult($this->answer, finishReason: 'stop', costRub: $this->cost);
        }

        public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
        {
            return new EmbeddingBatch([], 'fake', 1024);
        }

        public function chatModel(): string
        {
            return 'fake-chat';
        }

        public function embeddingModel(): string
        {
            return 'fake-embed';
        }

        public function embeddingDimensions(): int
        {
            return 1024;
        }
    };
}

function draftKbHit(string $title, string $text, float $score = 0.6): KbHit
{
    return new KbHit(
        chunkId: 'intertooler-page:dostavka-i-oplata#0',
        source: 'intertooler-page',
        url: 'https://intertooler.ru/page/dostavka-i-oplata',
        title: $title,
        breadcrumb: [$title],
        sectionPath: ['Оплата'],
        text: $text,
        score: $score,
    );
}

function articleDrafter(LlmClient $llm): KbArticleDrafter
{
    return new KbArticleDrafter($llm, new PiiRedactor, maxTokens: 1200, minScore: 0.42, shopName: 'Интертулер');
}

it('разбирает ответ модели в заголовок, абзацы и список недостающего', function (): void {
    $llm = draftingLlm(json_encode([
        'title' => 'Можно ли забрать заказ самовывозом?',
        'paragraphs' => ['Самовывоз согласуется с менеджером.', 'Оставьте контакты.'],
        'missing_facts' => ['по какому адресу возможен самовывоз?'],
    ], JSON_UNESCAPED_UNICODE), cost: 0.031);

    $draft = articleDrafter($llm)->draft(['можно ли забрать самому']);

    expect($draft->title)->toBe('Можно ли забрать заказ самовывозом')
        ->and($draft->paragraphs)->toBe(['Самовывоз согласуется с менеджером.', 'Оставьте контакты.'])
        ->and($draft->missingFacts)->toBe(['по какому адресу возможен самовывоз?'])
        ->and($draft->costRub)->toBe(0.031)
        ->and($draft->model)->toBe('fake-chat');
});

it('снимает обёртку ```json, которую модель ставит вопреки запрету', function (): void {
    $llm = draftingLlm("Готово:\n```json\n{\"title\":\"Гарантия\",\"paragraphs\":[\"Гарантию даёт производитель.\"]}\n```");

    expect(articleDrafter($llm)->draft(['какая гарантия'])->paragraphs)
        ->toBe(['Гарантию даёт производитель.']);
});

it('принимает ответ обычным текстом, а не теряет его из-за формата', function (): void {
    // JSON обязателен промптом, но не гарантирован им. Текст статьи в таком
    // ответе есть, и выбрасывать его из-за обёртки было бы расточительством.
    $llm = draftingLlm("Первый абзац ответа.\n\nВторой абзац ответа.");

    $draft = articleDrafter($llm)->draft(['есть ли рассрочка']);

    expect($draft->paragraphs)->toBe(['Первый абзац ответа.', 'Второй абзац ответа.'])
        // Заголовка модель не дала — берём его из вопроса покупателя.
        ->and($draft->title)->toBe('Есть ли рассрочка');
});

it('чистит персональные данные до отправки в шлюз', function (): void {
    // Вопрос покупателя уходит наружу, а маскирование на ключе шлюза берёт
    // не всякую запись телефона — слитную, например, не берёт.
    $llm = draftingLlm('{"paragraphs":["ответ"]}');

    articleDrafter($llm)->draft(['перезвоните на 89211234567 или напишите ivan@mail.ru']);

    expect($llm->user)->toContain('[телефон]')
        ->and($llm->user)->toContain('[email]')
        ->and($llm->user)->not->toContain('89211234567')
        ->and($llm->user)->not->toContain('ivan@mail.ru');
});

it('кладёт найденные фрагменты в запрос и не зовёт инструменты', function (): void {
    $llm = draftingLlm('{"paragraphs":["ответ"]}');

    articleDrafter($llm)->draft(
        ['можно ли оплатить по счёту'],
        [draftKbHit('Доставка и оплата', 'Для юридических лиц — оплата по счёту.')],
    );

    expect($llm->user)->toContain('Для юридических лиц — оплата по счёту.')
        ->and($llm->user)->toContain('Доставка и оплата')
        // Черновик собирается одним ходом: тул-лупу здесь взяться неоткуда.
        ->and($llm->tools)->toBe([])
        ->and($llm->maxTokens)->toBe(1200);
});

it('выбрасывает слабые совпадения, а не выдаёт их за материалы', function (): void {
    // Слабое совпадение — не «немного полезно», а правдоподобный контекст,
    // на котором строится неверный ответ.
    $llm = draftingLlm('{"paragraphs":["ответ"]}');

    articleDrafter($llm)->draft(
        ['есть ли рассрочка'],
        [
            draftKbHit('Производство', 'Сварка ленточных пил на ЧПУ.', score: 0.11),
            draftKbHit('Доставка и оплата', 'Для юридических лиц — оплата по счёту.', score: 0.51),
        ],
    );

    expect($llm->user)->toContain('Для юридических лиц — оплата по счёту.')
        ->and($llm->user)->not->toContain('Сварка ленточных пил на ЧПУ.');
});

it('материалы ниже порога — это отсутствие материалов, а не их наличие', function (): void {
    $llm = draftingLlm('{"paragraphs":["ответ"]}');

    articleDrafter($llm)->draft(['есть ли рассрочка'], [draftKbHit('Производство', 'Сварка пил.', score: 0.11)]);

    expect($llm->user)->toContain('ничего подходящего в базе магазина не нашлось');
});

it('прямо говорит модели, когда материалов нет вовсе', function (): void {
    // Пустой раздел молчанием модель заполняет сама — а это ровно то,
    // ради предотвращения чего весь черновик и задуман.
    $llm = draftingLlm('{"paragraphs":["ответ"]}');

    articleDrafter($llm)->draft(['бывает ли рассрочка']);

    expect($llm->user)->toContain('ничего подходящего в базе магазина не нашлось')
        ->and($llm->user)->toContain('выдумывать его запрещено')
        // Заготовка с пометками «[уточнить: …]» вместо отписки в одну строку:
        // человеку должно остаться вписать факты, а не писать статью с нуля.
        ->and($llm->user)->toContain('[уточнить: …]');
});

it('запрещает выдумывать факты в системном промпте и называет магазин', function (): void {
    $llm = draftingLlm('{"paragraphs":["ответ"]}');

    articleDrafter($llm)->draft(['сколько стоит доставка']);

    expect($llm->system)->toContain('Факты берутся ТОЛЬКО из материалов')
        ->and($llm->system)->toContain('missing_facts')
        ->and($llm->system)->toContain('«Интертулер»')
        ->and($llm->system)->not->toContain('KratonShop');
});

it('пустой ответ модели остаётся пустым черновиком, а не выдумкой', function (): void {
    $draft = articleDrafter(draftingLlm('   '))->draft(['что-нибудь']);

    expect($draft->isEmpty())->toBeTrue()
        ->and($draft->paragraphs)->toBe([]);
});

it('без вопросов не ходит в модель вовсе', function (): void {
    $llm = draftingLlm('{"paragraphs":["ответ"]}');

    expect(articleDrafter($llm)->draft(['   '])->isEmpty())->toBeTrue()
        ->and($llm->user)->toBe('');
});

it('складывает абзацы в документ редактора', function (): void {
    $llm = draftingLlm('{"title":"Гарантия","paragraphs":["Первый.","Второй."]}');

    $doc = articleDrafter($llm)->draft(['гарантия'])->toTiptap();

    expect($doc['type'])->toBe('doc')
        ->and($doc['content'])->toHaveCount(2)
        ->and($doc['content'][0])->toBe([
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Первый.']],
        ]);
});

it('строит статью по ответам менеджеров, а не по похожим кускам базы', function (): void {
    // Менеджер ответил сам и пометил ответ «в базу». Это единственный
    // материал, который приходит с готовым ответом магазина, — остальные
    // сигналы говорят лишь, чего нет.
    $llm = draftingLlm('{"title":"Отсрочка платежа","paragraphs":["Даём отсрочку постоянным клиентам."],"missing_facts":[]}');

    articleDrafter($llm)->draft(
        ['Есть ли отсрочка платежа?'],
        [],
        ['Постоянным клиентам даём отсрочку до 30 дней, разово — по согласованию.'],
    );

    expect($llm->user)->toContain('КАК НА ЭТО ОТВЕЧАЛИ МЕНЕДЖЕРЫ МАГАЗИНА:')
        ->and($llm->user)->toContain('Постоянным клиентам даём отсрочку до 30 дней')
        // Пустая база при живых ответах — не повод сказать «фактов нет»:
        // источник просто другой. У донора ровно здесь вышел пустой черновик.
        ->and($llm->user)->toContain('МАТЕРИАЛОВ В БАЗЕ НЕТ — пиши по ответам менеджеров выше.')
        ->and($llm->user)->not->toContain('выдумывать его запрещено');
});

it('чистит ответы менеджеров от персональных данных', function (): void {
    // Менеджер пишет живому человеку: зовёт по имени, поминает номер заказа
    // и телефон. Всё это уходит в шлюз вместе с материалом.
    $llm = draftingLlm('{"title":"Доставка","paragraphs":["Возим транспортными компаниями."],"missing_facts":[]}');

    articleDrafter($llm)->draft(
        ['Как доставите?'],
        [],
        ['Сергей, по вашему заказу отправим завтра, наберу вас на 89211234567.'],
    );

    expect($llm->user)->not->toContain('89211234567')
        ->and($llm->user)->toContain('отправим завтра');
});

it('без ответов менеджеров ведёт себя как прежде', function (): void {
    $llm = draftingLlm('{"title":"Возврат","paragraphs":["Принимаем возврат 14 дней."],"missing_facts":[]}');

    articleDrafter($llm)->draft(['Можно вернуть товар?'], []);

    expect($llm->user)->not->toContain('КАК НА ЭТО ОТВЕЧАЛИ МЕНЕДЖЕРЫ')
        ->and($llm->user)->toContain('МАТЕРИАЛЫ: ничего подходящего в базе магазина не нашлось.');
});
