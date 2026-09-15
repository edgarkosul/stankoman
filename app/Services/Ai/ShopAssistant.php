<?php

namespace App\Services\Ai;

use App\Livewire\Common\RequestCallback;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\AssistantReply;
use App\Services\Ai\Data\ToolCall;
use App\Services\Ai\Exceptions\PiiBlockedException;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Ai\Support\ProductLinkGuard;
use App\Services\Ai\Support\ReplyFormatter;
use App\Services\Ai\Tools\AssistantTool;
use App\Services\Ai\Tools\ToolContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ассистент магазина: цикл tool-use поверх LlmClient.
 *
 * Адаптация `siteko/app/Services/Ai/SupportAgent.php` — там этот цикл работает
 * в проде, и переписывать его заново значило бы заново собрать те же грабли.
 * Отличия два: формат OpenAI вместо нативного Anthropic и инструменты
 * отдельными классами вместо методов агента.
 */
final class ShopAssistant
{
    /**
     * @param  list<AssistantTool>  $tools
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly SystemPromptBuilder $prompts,
        private readonly PiiRedactor $redactor,
        private readonly ReplyFormatter $formatter,
        private readonly ProductLinkGuard $links,
        private readonly array $tools,
        private readonly int $maxIterations,
        private readonly int $maxTokens,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $history  предыдущие ходы диалога
     * @param  array<string, mixed>|null  $page  где находится посетитель
     * @param  array<string, string>  $settings  редактируемый слой промпта
     * @param  bool  $seesDiscounts  покупатель авторизован и видит цены со скидкой
     * @param  bool|null  $operatorsOnline  есть ли сейчас живой менеджер на связи
     * @param  string|null  $workingHours  часы работы для честной формулировки отказа
     */
    public function ask(
        string $question,
        array $history = [],
        ?string $sessionId = null,
        ?array $page = null,
        array $settings = [],
        bool $seesDiscounts = false,
        ?bool $operatorsOnline = null,
        ?string $workingHours = null,
    ): AssistantReply {
        $started = microtime(true);

        $context = new ToolContext(sessionId: $sessionId, page: $page, seesDiscounts: $seesDiscounts);
        $system = $this->prompts->build($page, $settings, $operatorsOnline, $workingHours);

        $definitions = array_map(
            static fn (AssistantTool $tool): array => $tool->definition(),
            $this->tools,
        );

        // Чистим всю исходящую историю, а не только новый вопрос: телефон,
        // написанный три хода назад, уходит в шлюз при каждом следующем
        // вызове. Не трогаем только написанное нами — контакты магазина,
        // названные ботом, портить нельзя (PiiRedactor::redactIncoming()).
        $messages = $this->redactor->redactIncoming([
            ...$history,
            ['role' => 'user', 'content' => $question, PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_VISITOR],
        ]);

        $inputTokens = 0;
        $outputTokens = 0;
        $cachedTokens = 0;
        $costRub = 0.0;

        // Кто ответил на самом деле. Берётся из тела ответа, а не из
        // нашего запроса: шлюз перепродаёт, и подмена модели — не гипотеза,
        // а случившийся на siteko инцидент.
        $model = '';

        for ($step = 0; $step < $this->maxIterations; $step++) {
            try {
                $result = $this->llm->chat(
                    system: $system,
                    messages: $this->redactor->withoutOrigin($messages),
                    tools: $definitions,
                    maxTokens: $this->maxTokens,
                    sessionId: $sessionId,
                    toolChoice: $this->toolChoice($step),
                );
            } catch (PiiBlockedException $e) {
                // Режим ключа переключается в панели шлюза, вне нашего кода.
                // Отдельная ветка, чтобы посетителю сказать про контакты,
                // а не «сервис недоступен».
                Log::warning('Assistant blocked by gateway PII filter', ['types' => $e->types]);

                return $this->failure('pii_blocked', $messages, $context, $started);
            } catch (Throwable $e) {
                Log::warning('Assistant call failed', [
                    'step' => $step,
                    'model' => $this->llm->chatModel(),
                    'error' => $e->getMessage(),
                ]);

                return $this->failure('error', $messages, $context, $started);
            }

            $inputTokens += $result->inputTokens;
            $outputTokens += $result->outputTokens;
            $cachedTokens += $result->cachedTokens;
            $costRub += $result->costRub;
            $model = $result->model ?: $model;

            if ($result->wantsTools()) {
                // Ассистентский ход возвращаем целиком: модель ждёт его
                // в истории рядом с результатами, иначе следующий вызов
                // придёт с висящими tool_call_id.
                $messages[] = [
                    'role' => 'assistant',
                    PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_BOT,
                    'content' => $result->content,
                    'tool_calls' => array_map(
                        static fn (ToolCall $call): array => [
                            'id' => $call->id,
                            'type' => 'function',
                            'function' => [
                                'name' => $call->name,
                                'arguments' => json_encode($call->arguments, JSON_UNESCAPED_UNICODE),
                            ],
                        ],
                        $result->toolCalls,
                    ),
                ];

                foreach ($result->toolCalls as $call) {
                    // Результат инструмента — контент магазина (реквизиты,
                    // телефоны сервисных центров), редактору его портить нельзя.
                    $messages[] = [
                        'role' => 'tool',
                        PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_BOT,
                        'tool_call_id' => $call->id,
                        'content' => $this->runTool($call, $context),
                    ];
                }

                continue;
            }

            $text = trim($result->content);

            if ($text !== '') {
                $messages[] = ['role' => 'assistant', 'content' => $text, PiiRedactor::ORIGIN => PiiRedactor::ORIGIN_BOT];
            }

            // Пусто или одни знаки препинания. На deepseek это не экзотика:
            // в замерах на siteko примерно каждый пятый прогон заканчивался
            // так. Заглушку не выдумываем — решает вызывающий.
            if ($this->isMeaningless($text)) {
                return $this->failure('empty', $messages, $context, $started,
                    $inputTokens, $outputTokens, $cachedTokens, $costRub, $model);
            }

            // Шлюз — перепродавец, и на siteko был случай, когда он подставил
            // чужую модель с чужим системным промптом. Проверка ответа
            // на выходе поэтому не формальность.
            if (self::isContaminated($text)) {
                Log::warning('Assistant reply contaminated', [
                    'model' => $this->llm->chatModel(),
                    'snippet' => Str::limit($text, 200),
                ]);

                return $this->failure('contaminated', $messages, $context, $started,
                    $inputTokens, $outputTokens, $cachedTokens, $costRub, $model);
            }

            /*
             * В ответе наша собственная заглушка. Это не ПДн покупателя, а
             * мёртвый литерал: свои синтетические подстановки шлюз
             * восстанавливает, наши — нечем. 13.09.2026 покупатель прочёл
             * «напишите нам на почту [email]», трижды пытался оставить
             * контакт и ушёл (диалог 17).
             *
             * Корень лечится выше, в redactIncoming(); здесь — сеть под ним.
             * Подставлять адрес магазина нельзя: «пишите нам на [email]» и
             * «ваша почта [email] получена» требуют противоположных замен.
             * Честно передать вопрос менеджеру лучше, чем показать скобки.
             */
            if (self::leaksPlaceholder($text)) {
                Log::warning('Assistant reply leaked redaction placeholder', [
                    'model' => $this->llm->chatModel(),
                    'snippet' => Str::limit($text, 200),
                ]);

                return $this->failure('placeholder_leak', $messages, $context, $started,
                    $inputTokens, $outputTokens, $cachedTokens, $costRub, $model);
            }

            /*
             * Бот назвал кнопку формы, но саму форму не вызвал. Кнопка
             * у покупателя появляется только по request_contact, и без флага
             * он искал бы на экране то, чего там нет. Приёмка 14.09.2026:
             * «нужен счёт — нажмите кнопку «Оставить контакты менеджеру»
             * под перепиской», а под перепиской пусто.
             *
             * Слова здесь верные — неверно их отсутствие на экране. Поэтому
             * делаем их правдой и показываем форму, а не прячем ответ.
             */
            if (! $context->callbackRequested && mb_stripos($text, RequestCallback::CONTACT_BUTTON) !== false) {
                $context->callbackRequested = true;

                Log::info('Assistant named the contact button without calling request_contact', [
                    'model' => $this->llm->chatModel(),
                ]);
            }

            return new AssistantReply(
                // Разметку снимаем последней, уже после гардов: они смотрят
                // на то, что модель написала на самом деле. Ссылки на
                // названные товары дописываются после форматирования —
                // иначе развёртка таблиц разобрала бы их обратно на части.
                text: $this->links->ensure(
                    // Цена для зарегистрированных, названная гостю, выбрасывается
                    // здесь же: суммы, которых он на витрине не видит, знает ход,
                    // а не форматировщик.
                    $this->formatter->format($text, $context->pricesToWithhold()),
                    $context->shownProducts,
                ),
                stopReason: $result->finishReason ?: 'stop',
                messages: $messages,
                toolCalls: $context->calls,
                citations: $context->citations,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                cachedTokens: $cachedTokens,
                costRub: $costRub,
                escalated: $context->escalated,
                callbackRequested: $context->callbackRequested,
                escalationReason: $context->escalationReason,
                callbackTopic: $context->callbackTopic,
                bestScore: $context->bestScore,
                questionEmbedding: $context->questionEmbedding,
                latencyMs: (int) ((microtime(true) - $started) * 1000),
                model: $model,
            );
        }

        return $this->failure('max_iterations', $messages, $context, $started,
            $inputTokens, $outputTokens, $cachedTokens, $costRub, $model);
    }

    /**
     * Инструменты разрешаем не на всех ходах.
     *
     * На ПЕРВОМ — требуем вызова: без этого модель охотно отвечает про условия
     * магазина «из головы», а знать их ей неоткуда.
     *
     * На ПОСЛЕДНЕМ — запрещаем: иначе она тратит его на очередной вызов, цикл
     * выходит с max_iterations, и посетитель получает заглушку вместо ответа.
     * Даже неполный ответ лучше «не получается ответить автоматически».
     *
     * При maxIterations = 1 запрет на «последнем» ходе означал бы запрет вообще,
     * поэтому такой случай отдельно.
     */
    private function toolChoice(int $step): ?string
    {
        if ($this->maxIterations > 1 && $step === $this->maxIterations - 1) {
            return 'none';
        }

        return $step === 0 ? 'required' : 'auto';
    }

    private function runTool(ToolCall $call, ToolContext $context): string
    {
        $startedAt = microtime(true);

        /*
         * Запись хода — в finally: она нужна одинаково и на успехе, и на
         * ошибке инструмента, и на выдуманном моделью имени. Именно эти
         * три случая потом и разбираются в админке, и пропасть из списка
         * вызовов они не должны.
         */
        try {
            foreach ($this->tools as $tool) {
                if ($tool->name() !== $call->name) {
                    continue;
                }

                try {
                    return $tool->run($call->arguments, $context);
                } catch (Throwable $e) {
                    Log::warning('Assistant tool failed', [
                        'tool' => $call->name,
                        'error' => $e->getMessage(),
                    ]);

                    return 'Инструмент вернул ошибку. Предложи связаться с менеджером.';
                }
            }

            // Модель выдумала инструмент. Отвечаем ей, а не падаем: следующий ход
            // она потратит на существующий.
            return 'Такого инструмента нет. Доступны: '.implode(', ', array_map(
                static fn (AssistantTool $tool): string => $tool->name(),
                $this->tools,
            )).'.';
        } finally {
            $context->recordCall(
                $call->name,
                $call->arguments,
                (int) ((microtime(true) - $startedAt) * 1000),
            );
        }
    }

    /** Ответ бессодержателен: пусто или одни знаки препинания вроде «.». */
    private function isMeaningless(string $text): bool
    {
        return preg_replace('/[\p{P}\p{Z}\s]+/u', '', $text) === '';
    }

    /**
     * В ответе маркеры, которых у консультанта KratonShop быть не может:
     * чужой системный промпт от шлюза или утечка модели и вендора, прямо
     * запрещённая замком идентичности.
     *
     * Только латиница со границами слова — кириллицу («Кирилл», «Клавдия»)
     * это не задевает. Компромисс осознанный: если бот упомянул имя даже
     * В ОТКАЗ («я не Claude»), ответ всё равно уводим к оператору. Случай
     * редкий — посетитель прощупывает идентичность, — а деградация мягкая.
     */
    public static function isContaminated(string $text): bool
    {
        return preg_match(
            '/\b(kiro|claude|anthropic|openai|chatgpt|gpt|deepseek|qwen|gigachat|yandexgpt|aitunnel|haiku|sonnet)\b/i',
            $text,
        ) === 1;
    }

    /**
     * В ответе заглушка редактора вместо живого контакта.
     *
     * Ложных срабатываний взяться неоткуда: эти три строки в проекте
     * встречаются только в PiiRedactor, а ссылки разметки выглядят как
     * `[текст](адрес)` и с ними не совпадают.
     */
    public static function leaksPlaceholder(string $text): bool
    {
        foreach ([PiiRedactor::EMAIL, PiiRedactor::PHONE, PiiRedactor::NUMBER] as $placeholder) {
            if (str_contains($text, $placeholder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function failure(
        string $reason,
        array $messages,
        ToolContext $context,
        float $started,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cachedTokens = 0,
        float $costRub = 0.0,
        string $model = '',
    ): AssistantReply {
        return new AssistantReply(
            text: '',
            stopReason: $reason,
            messages: $messages,
            toolCalls: $context->calls,
            citations: $context->citations,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cachedTokens: $cachedTokens,
            costRub: $costRub,
            escalated: $context->escalated,
            callbackRequested: $context->callbackRequested,
            escalationReason: $context->escalationReason,
            callbackTopic: $context->callbackTopic,
            bestScore: $context->bestScore,
            questionEmbedding: $context->questionEmbedding,
            latencyMs: (int) ((microtime(true) - $started) * 1000),
            model: $model,
        );
    }
}
