<?php

namespace App\Services\Ai\Data;

/**
 * Один ответ модели. Кроме текста несёт всё, без чего потом невозможно понять,
 * почему бот повёл себя так: причину остановки, вызовы инструментов, токены,
 * попадание в кэш и сработавшие детекторы ПДн.
 */
final readonly class ChatResult
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<string>  $maskedTypes  что шлюз распознал и подменил
     *                                     (`X-AITunnel-Masked-Types`)
     */
    public function __construct(
        public string $content,
        public array $toolCalls = [],
        public string $finishReason = '',
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedTokens = 0,
        public float $costRub = 0.0,
        public array $maskedTypes = [],
        /**
         * Какая модель ответила НА САМОМ ДЕЛЕ — из тела ответа, а не из
         * нашего запроса. Шлюз перепродаёт, и на siteko был случай, когда
         * он подставил чужую модель с чужим системным промптом. Пока это
         * поле не записывалось, отличить «модель стала хуже» от «нам
         * подсунули другую» было нечем.
         */
        public string $model = '',
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * Модель ответила пустотой и ничего не попросила. На deepseek это не
     * экзотика: примерно каждый пятый прогон в замерах на siteko упирался
     * в потолок шагов и отдавал именно это. Обрабатывать как штатный исход.
     */
    public function isEmpty(): bool
    {
        return trim($this->content) === '' && ! $this->wantsTools();
    }
}
