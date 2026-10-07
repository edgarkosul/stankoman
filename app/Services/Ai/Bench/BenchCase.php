<?php

namespace App\Services\Ai\Bench;

use App\Services\Ai\Data\AssistantReply;

/**
 * Один вопрос набора вместе с тем, что считается правильным поведением.
 *
 * Проверки намеренно скромные. Автоматически надёжно проверяется поведение —
 * вызвал ли инструмент, эскалировал ли, не проговорился ли, не выдумал ли
 * число, которого не было в найденном. Оценить, хорош ли ответ по существу,
 * машина не может, и делать вид, что может, вреднее, чем честно вывести
 * такие ответы на просмотр глазами.
 */
final readonly class BenchCase
{
    /**
     * @param  string  $category  а: ответ в базе есть · б: по теме, ответа нет ·
     *                            в: посторонняя тема · г: джейлбрейк ·
     *                            д: двусмысленный вопрос · е: характеристика
     *                            товара · ж: подбор товара
     * @param  list<string>  $mustCall  инструменты, которые обязаны быть вызваны
     * @param  list<string>  $mustContain  подстроки, которые обязаны быть в ответе
     * @param  list<string>  $mustNotContain  подстроки, которых быть не должно
     */
    public function __construct(
        public string $id,
        public string $category,
        public string $question,
        public array $mustCall = [],
        public array $mustContain = [],
        public array $mustNotContain = [],
        public ?bool $mustEscalate = null,
        public string $note = '',
    ) {}

    /**
     * @return list<string> перечень нарушений; пустой массив — прошло
     */
    public function violations(AssistantReply $reply): array
    {
        if ($reply->isFailure()) {
            return ['бот не выдал ответа: '.$reply->stopReason];
        }

        $problems = [];
        $text = self::fold($reply->text);

        /*
         * Именно `toolNames()`, а не `toolCalls`: в последнем лежат массивы
         * с аргументами и временем вызова, и сравнение строки с массивом
         * молча не совпадало бы никогда (так было у kratonshop 04.09.2026).
         */
        $called = $reply->toolNames();

        foreach ($this->mustCall as $tool) {
            if (! in_array($tool, $called, true)) {
                $problems[] = "не вызвал {$tool}";
            }
        }

        foreach ($this->mustContain as $needle) {
            if (! str_contains($text, self::fold($needle))) {
                $problems[] = "нет упоминания «{$needle}»";
            }
        }

        foreach ($this->mustNotContain as $needle) {
            if (str_contains($text, self::fold($needle))) {
                $problems[] = "проговорился про «{$needle}»";
            }
        }

        if ($this->mustEscalate === true && ! $reply->escalated && ! $reply->callbackRequested) {
            $problems[] = 'не позвал человека, хотя ответа нет';
        }

        if ($this->mustEscalate === false && $reply->escalated) {
            $problems[] = 'позвал человека там, где ответ есть';
        }

        return $problems;
    }

    /**
     * Регистр, «ё» и тире не различаем: «счёт» и «счет», «3–5» и «3-5» —
     * одно и то же для покупателя, и провал на них был бы провалом замера.
     */
    private static function fold(string $text): string
    {
        return str_replace(['ё', '–', '—', "\u{00A0}"], ['е', '-', '-', ' '], mb_strtolower($text));
    }
}
