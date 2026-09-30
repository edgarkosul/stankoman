<?php

namespace App\Services\Kb;

/**
 * Заголовок статьи из реплики покупателя.
 *
 * Мост «диалог → база знаний» подставляет вопрос в заголовок как есть,
 * и это верно: форма редактора прямо просит формулировать «как спрашивает
 * покупатель». Но реплика приходит из чата, а не из формы, — в ней бывают
 * переносы строк, хвостовой вопросительный знак и строчная буква в начале.
 *
 * Правка чисто косметическая и намеренно неглубокая: смысл фразы не
 * трогаем вовсе, потому что заголовок человек всё равно видит перед собой
 * и правит руками. Задача помощника — чтобы поле не выглядело мусором.
 */
final class KbArticleTitle
{
    /** Столько же, сколько в колонке и в правиле формы. */
    private const MAX_LENGTH = 255;

    /**
     * Ниже этой границы обрезка по слову не спасает: остаток слишком
     * короток, чтобы считаться той же фразой, и резать надо по букве.
     */
    private const MIN_WORD_CUT = 200;

    public static function fromQuestion(string $question): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $question) ?? '');

        // Хвостовая пунктуация в заголовке лишняя: «Можно ли вернуть товар»
        // читается как строка списка, «Можно ли вернуть товар???» — как крик.
        $text = preg_replace('/[\s?!.,;:…]+$/u', '', $text) ?? '';

        if ($text === '') {
            return '';
        }

        $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);

        return mb_strlen($text) > self::MAX_LENGTH ? self::shorten($text) : $text;
    }

    /** Обрезка по границе слова, с многоточием вместо обрубка. */
    private static function shorten(string $text): string
    {
        $cut = mb_substr($text, 0, self::MAX_LENGTH - 1);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space >= self::MIN_WORD_CUT) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut).'…';
    }
}
