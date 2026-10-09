<?php

namespace App\Services\Ai\Providers;

/**
 * Чем API канала отличается от соседа при одном формате OpenAI: куда класть
 * уровень рассуждений, что нужно в истории и откуда брать цену вызова.
 *
 *   aitunnel — `reasoning_effort`, выключение — `reasoning.enabled`, цена — `usage.cost_rub`;
 *   DeepSeek — `reasoning_effort`, выключение — `thinking.type`, цены в ответе нет.
 *
 * Перенесено из bots (6c62f89, 0bc953d), без RouterAI.
 */
enum ApiDialect: string
{
    case Aitunnel = 'aitunnel';

    case DeepSeek = 'deepseek';

    /**
     * Поля рассуждений запроса.
     *
     * Сам DeepSeek с рассуждениями не принимает обязательный вызов инструмента:
     * `tool_choice: required` и выбор по имени дают 400 «Thinking mode does not
     * support this tool_choice» (пробы bots с srv2 08.10.2026). Такой вызов идёт
     * без рассуждений — тогда он проходит. Наш первый шаг ответа — `required`
     * (ShopAssistant::toolChoice), значит у DeepSeek он всегда без рассуждений.
     *
     * @param  string  $reasoning  ai_support.agent.reasoning: '' | low | minimal | off
     * @param  string|null  $toolChoice  что уйдёт в `tool_choice`; null — поля не будет
     * @return array<string, mixed>
     */
    public function reasoning(string $reasoning, ?string $toolChoice = null): array
    {
        if ($this === self::DeepSeek) {
            $forced = $toolChoice !== null && ! in_array($toolChoice, ['auto', 'none'], true);

            return match (true) {
                $reasoning === 'off', $forced => ['thinking' => ['type' => 'disabled']],
                $reasoning === '' => [],
                // Уровни у DeepSeek — low, high, max: `minimal` нет.
                default => ['reasoning_effort' => $reasoning === 'minimal' ? 'low' : $reasoning],
            };
        }

        /*
         * Шлюз понимает оба вида: reasoning_effort (как у OpenAI) и
         * reasoning.enabled (как у OpenRouter). Выключить совсем умеет только
         * второй: thinking.type=disabled оставлял десятки токенов рассуждений.
         */
        return match ($reasoning) {
            '' => [],
            'off' => ['reasoning' => ['enabled' => false]],
            default => ['reasoning_effort' => $reasoning],
        };
    }

    /**
     * История в том виде, в каком её ждёт API канала.
     *
     * DeepSeek при рассуждениях и инструментах требует у каждого вызова
     * инструмента в истории `reasoning_content` — рассуждение, после которого
     * вызов сделан. Нет поля — 400 «The `reasoning_content` in the thinking
     * mode must be passed back», и FailoverLlmClient молча уводит ответ
     * в запасной канал. Вызовы, сделанные с рассуждениями, DeepSeek помнит
     * сам, по id, но это нигде не описано. А вызов без рассуждений — первый
     * шаг ответа (`required`, см. reasoning()), повтор пустого ответа, ход
     * запасного канала — без поля не проходит никогда. Пустая строка годится.
     *
     * Прошлые ходы диалога — простой текст, поле им не нужно.
     *
     * Шлюзу поле не шлём: что он делает с незнакомым полем, не проверяли.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function history(array $messages): array
    {
        return array_map(function (array $message): array {
            if ($this !== self::DeepSeek) {
                unset($message['reasoning_content']);

                return $message;
            }

            if (($message['role'] ?? '') === 'assistant' && ($message['tool_calls'] ?? []) !== []) {
                $message['reasoning_content'] = (string) ($message['reasoning_content'] ?? '');
            }

            return $message;
        }, $messages);
    }

    /** Поле цены в рублях в `usage`; null — цены в ответе нет, её считает DeepSeekPricing. */
    public function costField(): ?string
    {
        return match ($this) {
            self::Aitunnel => 'cost_rub',
            self::DeepSeek => null,
        };
    }
}
