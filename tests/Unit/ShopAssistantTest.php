<?php

use App\Livewire\Common\RequestCallback;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Data\ProductCard;
use App\Services\Ai\Data\ToolCall;
use App\Services\Ai\ShopAssistant;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Support\ProductLinkGuard;
use App\Services\Ai\Support\ReplyFormatter;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Ai\Tools\AssistantTool;
use App\Services\Ai\Tools\ToolContext;
use Tests\TestCase;

// Агент пишет в лог на провалах — нужен поднятый контейнер.
uses(TestCase::class);

/** Модель, отдающая заранее записанные ходы. Сети и денег не требует. */
function scriptedLlm(array $steps): LlmClient
{
    return new class($steps) implements LlmClient
    {
        public array $seen = [];

        public function __construct(private array $steps) {}

        public function chat(string $system, array $messages, array $tools = [],
            ?int $maxTokens = null, ?string $sessionId = null, ?string $toolChoice = null): ChatResult
        {
            $this->seen[] = ['toolChoice' => $toolChoice, 'messages' => $messages, 'system' => $system];

            return array_shift($this->steps) ?? new ChatResult('конец', finishReason: 'stop');
        }

        public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
        {
            return new EmbeddingBatch([], 'fake', 1024);
        }

        public function chatModel(): string
        {
            return 'fake';
        }

        public function embeddingModel(): string
        {
            return 'fake';
        }

        public function embeddingDimensions(): int
        {
            return 1024;
        }
    };
}

/** Инструмент-пустышка с заданным именем и ответом. */
function scriptedTool(string $name, string $result = 'ок'): AssistantTool
{
    return new class($name, $result) implements AssistantTool
    {
        public int $calls = 0;

        public function __construct(private string $toolName, private string $result) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function definition(): array
        {
            return ['type' => 'function', 'function' => ['name' => $this->toolName]];
        }

        public function run(array $arguments, ToolContext $context): string
        {
            $this->calls++;

            return $this->result;
        }
    };
}

function assistant(LlmClient $llm, array $tools = [], int $maxIterations = 4): ShopAssistant
{
    return new ShopAssistant(
        llm: $llm,
        prompts: new SystemPromptBuilder('InterTooler.ru'),
        redactor: new PiiRedactor,
        formatter: new ReplyFormatter,
        links: new ProductLinkGuard,
        tools: $tools,
        maxIterations: $maxIterations,
        maxTokens: 512,
    );
}

it('прогоняет цикл: вызов инструмента, затем ответ', function (): void {
    $tool = scriptedTool('search_knowledge_base', 'Оплата по счёту.');

    $reply = assistant(scriptedLlm([
        new ChatResult('', [new ToolCall('c1', 'search_knowledge_base', ['query' => 'оплата'])], 'tool_calls'),
        new ChatResult('Юрлица платят по счёту.', finishReason: 'stop'),
    ]), [$tool])->ask('как платить');

    expect($reply->text)->toBe('Юрлица платят по счёту.')
        ->and($reply->toolNames())->toBe(['search_knowledge_base'])
        // Аргументы вызова сохраняются: без них потом не разобрать,
        // что бот искал, когда ответил мимо.
        ->and($reply->toolCalls[0]['arguments'])->toBe(['query' => 'оплата'])
        ->and($tool->calls)->toBe(1)
        ->and($reply->isFailure())->toBeFalse();
});

it('скрывает ответ, где модель проговорилась об идентичности', function (): void {
    // Шлюз — перепродавец, и на siteko он однажды подставил чужую модель
    // с чужим системным промптом. Проверка ответа на выходе не формальность.
    $reply = assistant(scriptedLlm([
        new ChatResult('Я Claude, ассистент Anthropic.', finishReason: 'stop'),
    ]))->ask('ты кто');

    expect($reply->stopReason)->toBe('contaminated')
        ->and($reply->text)->toBe('')
        ->and($reply->isFailure())->toBeTrue();
});

it('не считает заражением кириллицу, похожую на бренд', function (): void {
    $reply = assistant(scriptedLlm([
        new ChatResult('Доставим в Клавдиево, менеджер Кирилл перезвонит.', finishReason: 'stop'),
    ]))->ask('доставка');

    expect($reply->stopReason)->toBe('stop');
});

it('считает провалом пустой ответ и ответ из одной точки', function (): void {
    foreach (['', '   ', '.', '...'] as $text) {
        $reply = assistant(scriptedLlm([new ChatResult($text, finishReason: 'stop')]))->ask('вопрос');

        expect($reply->stopReason)->toBe('empty');
    }
});

it('выходит по max_iterations, если модель зациклилась на инструментах', function (): void {
    // Штатный исход, а не авария: на deepseek примерно каждый пятый прогон
    // в замерах на siteko заканчивался именно так.
    $steps = array_fill(0, 5, new ChatResult('', [new ToolCall('c', 'search_knowledge_base', [])], 'tool_calls'));

    $reply = assistant(scriptedLlm($steps), [scriptedTool('search_knowledge_base')], maxIterations: 3)
        ->ask('вопрос');

    expect($reply->stopReason)->toBe('max_iterations')
        ->and($reply->isFailure())->toBeTrue()
        // Заглушку агент не выдумывает: что показать — решает вызывающий.
        ->and($reply->text)->toBe('');
});

it('требует инструмент на первом ходе и запрещает на последнем', function (): void {
    $llm = scriptedLlm(array_fill(0, 3, new ChatResult('', [new ToolCall('c', 't', [])], 'tool_calls')));

    assistant($llm, [scriptedTool('t')], maxIterations: 3)->ask('вопрос');

    // Первый ход — required: иначе модель отвечает про условия магазина
    // «из головы», а знать их ей неоткуда. Последний — none: иначе ход
    // уйдёт на очередной вызов, и посетитель получит заглушку.
    expect(array_column($llm->seen, 'toolChoice'))->toBe(['required', 'auto', 'none']);
});

it('отвечает модели, а не падает, когда та выдумала инструмент', function (): void {
    $reply = assistant(scriptedLlm([
        new ChatResult('', [new ToolCall('c', 'нет_такого', [])], 'tool_calls'),
        new ChatResult('Готово.', finishReason: 'stop'),
    ]), [scriptedTool('search_knowledge_base')])->ask('вопрос');

    expect($reply->text)->toBe('Готово.');
});

it('переживает падение инструмента и даёт модели шанс ответить', function (): void {
    $broken = new class implements AssistantTool
    {
        public function name(): string
        {
            return 'broken';
        }

        public function definition(): array
        {
            return ['type' => 'function', 'function' => ['name' => 'broken']];
        }

        public function run(array $arguments, ToolContext $context): string
        {
            throw new RuntimeException('база отвалилась');
        }
    };

    $reply = assistant(scriptedLlm([
        new ChatResult('', [new ToolCall('c', 'broken', [])], 'tool_calls'),
        new ChatResult('Не получается уточнить, передам менеджеру.', finishReason: 'stop'),
    ]), [$broken])->ask('вопрос');

    expect($reply->text)->toBe('Не получается уточнить, передам менеджеру.');
});

it('чистит в исходящей истории всё, кроме написанного ботом', function (): void {
    $llm = scriptedLlm([new ChatResult('Хорошо.', finishReason: 'stop')]);

    assistant($llm)->ask('а мой номер 89211234567', [
        ['role' => 'user', 'content' => 'мой номер 89211234567', PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_VISITOR],
        // Ответ бота с контактами магазина. Диалог 17 (13.09.2026): редактор
        // превращал их в заглушки, и модель копировала «[email]» в новый ответ.
        ['role' => 'assistant', 'content' => 'Пишите на sales@intertooler.ru или звоните +7 (900) 246-86-60, счёт 40702810036260006735',
            PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_BOT],
        // Живой менеджер едет под той же ролью assistant — и его чистить надо.
        ['role' => 'assistant', 'content' => 'Это Пётр, звоните мне на 89219998877',
            PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_OPERATOR],
    ]);

    // Телефон, написанный три хода назад, уходит в шлюз при КАЖДОМ
    // следующем вызове — чистить только новый вопрос недостаточно.
    $sent = $llm->seen[0]['messages'];

    expect($sent[0]['content'])->toBe('мой номер [телефон]')
        ->and($sent[1]['content'])->toBe('Пишите на sales@intertooler.ru или звоните +7 (900) 246-86-60, счёт 40702810036260006735')
        ->and($sent[2]['content'])->toBe('Это Пётр, звоните мне на [телефон]')
        ->and($sent[3]['content'])->toBe('а мой номер [телефон]');

    // Своя пометка в шлюз не уходит: он о ней ничего не знает.
    foreach ($sent as $message) {
        expect($message)->not->toHaveKey(PiiRedactor::ORIGIN);
    }
});

it('на следующем ходу не портит контакты магазина из истории, которую вернул сам агент', function (): void {
    // Так ведёт разговор `ai:chat`: скармливает агенту его же $reply->messages,
    // с вызовами инструментов и их результатами.
    $first = assistant(scriptedLlm([
        new ChatResult('', [new ToolCall('c1', 'search_knowledge_base', ['query' => 'контакты'])], 'tool_calls'),
        new ChatResult('Пишите на sales@intertooler.ru.', finishReason: 'stop'),
    ]), [scriptedTool('search_knowledge_base', 'Почта магазина sales@intertooler.ru')])->ask('куда писать');

    $llm = scriptedLlm([new ChatResult('Хорошо.', finishReason: 'stop')]);
    assistant($llm)->ask('повторите адрес', $first->messages);

    $contents = implode("\n", array_column($llm->seen[0]['messages'], 'content'));

    expect($contents)->toContain('Почта магазина sales@intertooler.ru')
        ->and($contents)->toContain('Пишите на sales@intertooler.ru.')
        ->and($contents)->not->toContain(PiiRedactor::EMAIL);
});

it('не показывает покупателю заглушку редактора вместо контакта', function (): void {
    foreach (['Пишите нам на почту [email].', 'Звоните по номеру [телефон].', 'Счёт для оплаты [номер].'] as $text) {
        $reply = assistant(scriptedLlm([new ChatResult($text, finishReason: 'stop')]))->ask('вопрос');

        expect($reply->stopReason)->toBe('placeholder_leak')
            ->and($reply->text)->toBe('')
            ->and($reply->isFailure())->toBeTrue();
    }
});

it('показывает форму, если бот назвал её кнопку, не вызвав инструмент', function (): void {
    // Приёмка 14.09.2026: «нажмите кнопку «Оставить контакты менеджеру»
    // под перепиской» — а кнопки на экране не было, форму никто не вызывал.
    $named = assistant(scriptedLlm([
        new ChatResult('Нужен счёт — нажмите **«'.RequestCallback::CONTACT_BUTTON.'»** под перепиской.', finishReason: 'stop'),
    ]))->ask('нужен счёт');

    $plain = assistant(scriptedLlm([
        new ChatResult('Оплатить можно по счёту через банк.', finishReason: 'stop'),
    ]))->ask('как оплатить');

    expect($named->callbackRequested)->toBeTrue()
        ->and($named->stopReason)->toBe('stop')
        ->and($plain->callbackRequested)->toBeFalse();
});

it('не принимает ссылку разметки за заглушку редактора', function (): void {
    $reply = assistant(scriptedLlm([
        new ChatResult('Подробнее — [Способы оплаты](https://intertooler.ru/page/dostavka-i-oplata).', finishReason: 'stop'),
    ]))->ask('как оплатить');

    expect($reply->stopReason)->toBe('stop')
        ->and($reply->isFailure())->toBeFalse();
});

it('поднимает флаги эскалации и заявки из контекста инструментов', function (): void {
    $escalate = new class implements AssistantTool
    {
        public function name(): string
        {
            return 'escalate_to_operator';
        }

        public function definition(): array
        {
            return ['type' => 'function', 'function' => ['name' => 'escalate_to_operator']];
        }

        public function run(array $arguments, ToolContext $context): string
        {
            $context->escalated = true;

            return 'передано';
        }
    };

    $reply = assistant(scriptedLlm([
        new ChatResult('', [new ToolCall('c', 'escalate_to_operator', [])], 'tool_calls'),
        new ChatResult('Передал менеджеру.', finishReason: 'stop'),
    ]), [$escalate])->ask('вопрос');

    expect($reply->escalated)->toBeTrue();
});

it('сохраняет разметку в итоговом ответе', function (): void {
    // С фазы 3.10 звёздочки не срезаются: их разбирает ChatMarkdown в ленте.
    // Срезать их здесь означало бы потерять списки и ссылки на товары —
    // ровно то, ради чего разметку и включали.
    $reply = assistant(scriptedLlm([
        new ChatResult('Цена — **402 659 руб.**', finishReason: 'stop'),
    ]))->ask('цена');

    expect($reply->text)->toBe('Цена — **402 659 руб.**');
});

it('прячет упоминания базы знаний и в ответе агента', function (): void {
    // А вот это `ReplyFormatter` по-прежнему делает: покупателю нет дела
    // до устройства нашей памяти.
    $reply = assistant(scriptedLlm([
        new ChatResult('В базе знаний магазина указано, что гарантия год.', finishReason: 'stop'),
    ]))->ask('гарантия');

    expect($reply->text)->toBe('У нас указано, что гарантия год.');
});

it('суммирует токены и стоимость по всем ходам цикла', function (): void {
    $reply = assistant(scriptedLlm([
        new ChatResult('', [new ToolCall('c', 't', [])], 'tool_calls', inputTokens: 100, outputTokens: 10, costRub: 0.01),
        new ChatResult('Ответ.', finishReason: 'stop', inputTokens: 200, outputTokens: 20, costRub: 0.02),
    ]), [scriptedTool('t')])->ask('вопрос');

    expect($reply->inputTokens)->toBe(300)
        ->and($reply->outputTokens)->toBe(30)
        ->and($reply->costRub)->toBe(0.03);
});

it('видит повтор одного и того же вызова инструмента в ходе', function (): void {
    // Наблюдение 04.09.2026: на «универсальный пылесос до сорока тысяч»
    // модель шесть раз подряд позвала один и тот же поиск. Каждый вызов —
    // ещё одно обращение к модели, а результаты предыдущих остаются
    // в контексте: ход рос квадратично, 0.85 ₽ против 0.06 ₽ у обычного.
    $context = new ToolContext;

    expect($context->alreadyCalled('search_products', ['query' => 'пылесос']))->toBeFalse();

    $context->recordCall('search_products', ['query' => 'пылесос'], 12);

    expect($context->alreadyCalled('search_products', ['query' => 'пылесос']))->toBeTrue()
        // Регистр и порядок ключей не делают повтор новым вызовом.
        ->and($context->alreadyCalled('search_products', ['query' => 'ПЫЛЕСОС ']))->toBeTrue()
        // А другой запрос и другой инструмент — делают.
        ->and($context->alreadyCalled('search_products', ['query' => 'компрессор']))->toBeFalse()
        ->and($context->alreadyCalled('browse_categories', ['query' => 'пылесос']))->toBeFalse();
});

it('различает вызовы поиска с разными фильтрами', function (): void {
    $context = new ToolContext;
    $context->recordCall('search_products', ['query' => 'пылесос', 'price_max' => 40000], 10);

    expect($context->alreadyCalled('search_products', ['price_max' => 40000, 'query' => 'пылесос']))->toBeTrue()
        ->and($context->alreadyCalled('search_products', ['query' => 'пылесос', 'price_max' => 20000]))->toBeFalse();
});

it('не прячет сумму, которая стоит на витрине открыто', function (): void {
    // Членская цена одного товара может совпасть с обычной ценой соседа
    // по той же выдаче, и выбрасывать из ответа честную цену соседа нельзя.
    $context = new ToolContext;

    $context->noteCard(new ProductCard(
        id: 1,
        name: 'Ленточнопильный станок METAL MASTER BSM-115',
        url: 'https://intertooler.ru/product/bs-115',
        sku: 'BSM-115',
        brand: 'MetalMaster',
        inStock: true,
        price: 103166,
        priceNote: '',
        vatNote: '',
        warranty: null,
        specs: [],
        description: null,
    ));

    $context->withholdPrices([103166, 95000]);

    expect($context->pricesToWithhold())->toBe([95000]);
});

it('гостю не показывает цену для зарегистрированных, даже если модель её назвала', function (): void {
    /*
     * Сквозная проверка последнего слоя защиты: инструмент сообщает ходу,
     * какие суммы этот посетитель на витрине не видит, а агент отдаёт их
     * форматировщику. Ни промпт, ни устройство карточки в этом тесте
     * не участвуют — проверяется именно сеть под ними.
     */
    $withholding = new class implements AssistantTool
    {
        public function name(): string
        {
            return 'search_products';
        }

        public function definition(): array
        {
            return ['type' => 'function', 'function' => ['name' => 'search_products']];
        }

        public function run(array $arguments, ToolContext $context): string
        {
            $context->withholdPrices([103166]);

            return 'Цена: 108 596 руб.';
        }
    };

    $reply = assistant(scriptedLlm([
        new ChatResult('', [new ToolCall('c', 'search_products', ['query' => 'станок'])], 'tool_calls'),
        new ChatResult('Цена 108 596 руб. Со скидкой выйдет 103 166 руб. Оформить?', finishReason: 'stop'),
    ]), [$withholding])->ask('сколько стоит станок');

    expect($reply->text)->toBe('Цена 108 596 руб. Оформить?');
});
