<?php

namespace App\Services\Ai\Support;

/**
 * Вызов инструмента, который модель написала текстом, а не вызвала.
 *
 * deepseek так делает, когда инструменты запрещены (последний ход цикла):
 * замер bots 29.09.2026 — ответ заканчивается
 * `<｜DSML｜tool_calls><｜DSML｜invoke name="request_contact">…`, а v4.1 пишет
 * `<｜DSML｜ calls>`. ChatMarkdown показывает разметку как текст — посетитель
 * читает служебные теги. Вызова по тексту не делаем: разрешения на него не было.
 */
final class ToolMarkup
{
    /** Черта — полноширинная `｜` (так шлёт модель) или обычная; у v4.1 — `<｜DSML｜ calls>`. */
    private const BLOCK = '/<[｜|]\s*DSML\s*[｜|]\s*(tool_)?calls\s*>.*?<\/[｜|]\s*DSML\s*[｜|]\s*(tool_)?calls\s*>'
        .'|<[｜|]\s*tool[▁_ ]calls[▁_ ]begin\s*[｜|]>.*?<[｜|]\s*tool[▁_ ]calls[▁_ ]end\s*[｜|]>/su';

    /** Начало вызова без конца (ответ оборвался на потолке токенов) — режем до конца текста. */
    private const OPEN = '/<\/?[｜|]\s*(DSML\s*[｜|]|tool[▁_ ]call)/u';

    public static function in(string $text): bool
    {
        return preg_match(self::OPEN, $text) === 1;
    }

    public static function strip(string $text): string
    {
        if (! self::in($text)) {
            return $text;
        }

        $text = (string) preg_replace(self::BLOCK, '', $text);

        if (preg_match(self::OPEN, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $text = substr($text, 0, $match[0][1]);
        }

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));
    }
}
