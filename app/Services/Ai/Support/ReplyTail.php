<?php

namespace App\Services\Ai\Support;

/**
 * Хвост ответа, оборванного на потолке токенов (`finish_reason: length`).
 *
 * Потолок делят рассуждения модели и сам текст, и длинный ответ — подбор из
 * четырёх товаров с характеристиками — в него не помещается: в замерах bots
 * 28–30.09.2026 это 1–3 прогона из 63. Посетитель читал «…колёса 29″
 * и воздушная в» или пункт списка из одной ссылки без закрывающей скобки.
 *
 * Дописать за модель нельзя, поэтому режем до последнего целого места:
 * оборванная строка уходит целиком, а если в ней были законченные
 * предложения — остаются они. Короче, но целое.
 */
final class ReplyTail
{
    /** Чем кончается законченное предложение; кавычка и скобка после точки — тоже конец. */
    private const SENTENCE_END = '/[.!?…][»")\]]*(?=\s|$)/u';

    /** Строка-разделитель или пустой маркер списка: без продолжения они ничего не значат. */
    private const DANGLING = '/^\s*(([-*_]\s*){3,}|[-*•]|\d+[.)]|#{1,6})?\s*$/u';

    public static function complete(string $text): string
    {
        $lines = explode("\n", rtrim($text));
        $last = (string) array_pop($lines);

        $kept = self::sentences($last);

        if ($kept !== '') {
            $lines[] = $kept;
        }

        while ($lines !== [] && preg_match(self::DANGLING, (string) end($lines)) === 1) {
            array_pop($lines);
        }

        $result = rtrim(implode("\n", $lines));

        // Резать оказалось нечего или после срезки ничего не осталось —
        // оборванный текст лучше пустого.
        return $result === '' ? rtrim($text) : $result;
    }

    /**
     * Законченные предложения оборванной строки. Пусто — строка уходит
     * целиком: в ней нет ни одной точки либо после срезки осталась
     * незакрытая разметка (`**полужирный`, `[ссылка](адрес`).
     */
    private static function sentences(string $line): string
    {
        if (preg_match_all(self::SENTENCE_END, $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return '';
        }

        foreach (array_reverse($matches[0]) as [$end, $offset]) {
            $kept = substr($line, 0, $offset + strlen($end));

            if (self::balanced($kept)) {
                return $kept;
            }
        }

        return '';
    }

    private static function balanced(string $text): bool
    {
        return substr_count($text, '**') % 2 === 0
            && substr_count($text, '[') === substr_count($text, ']')
            && substr_count($text, '(') === substr_count($text, ')')
            // Точка внутри адреса или числа («27.5», «intertooler.ru») — не конец.
            && preg_match('/\]\([^)]*$/u', $text) !== 1;
    }
}
