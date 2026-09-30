<?php

namespace App\Services\Ai\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Прячет в ответе модели то, чего покупатель видеть не должен.
 *
 * Раньше класс делал вдвое больше: снимал markdown регекспами, потому что
 * лента рендерила обычный текст и звёздочки покупатель видел буквально.
 * С фазы 3.10 разметку разбирает настоящий парсер (`ChatMarkdown`), и вся
 * эта половина удалена — регекспы по разметке по своей природе требуют
 * заплаток вечно, а парсер их не требует.
 *
 * Осталось то, что парсером не решается: обороты, выдающие устройство бота.
 * Промпт их запрещает, и запрет в основном работает — но «в основном» здесь
 * мало, см. `hideInternals()`.
 */
final class ReplyFormatter
{
    /**
     * Markdown-таблица в строки.
     *
     * Таблиц в ленте нет намеренно: панель чата шириной в 380 пикселей,
     * и таблица в ней не читается — расширение парсера не подключено
     * с фазы 3.10. Промпт таблицы запрещает, но 04.09.2026 модель выдала
     * их дважды подряд, и покупатель увидел сырые палки: «| Модель | Цена |».
     *
     * Правило, не сработавшее дважды, переносится в код. Строка таблицы
     * становится строкой списка: первая ячейка — жирным, остальные через
     * точку. Шапка выбрасывается: в узкой панели «Модель · Цена · Ход
     * штока» занимает место, ничего не объясняя, — значения и так
     * говорят за себя.
     */
    private function flattenTables(string $text): string
    {
        if (! str_contains($text, '|')) {
            return $text;
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        $inTable = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            $isRow = $trimmed !== ''
                && str_starts_with($trimmed, '|')
                && str_ends_with($trimmed, '|')
                && substr_count($trimmed, '|') >= 3;

            if (! $isRow) {
                $inTable = false;
                $out[] = $line;

                continue;
            }

            $cells = array_values(array_filter(
                array_map('trim', explode('|', trim($trimmed, '|'))),
                static fn (string $cell): bool => $cell !== '',
            ));

            // Разделитель шапки: |---|---|
            if ($cells === [] || preg_match('/^:?-{2,}:?$/', $cells[0]) === 1) {
                continue;
            }

            // Первая строка таблицы — шапка, она же и открывает блок.
            if (! $inTable) {
                $inTable = true;

                continue;
            }

            $first = array_shift($cells);

            $out[] = $cells === []
                ? '- **'.$first.'**'
                : '- **'.$first.'** — '.implode(' · ', $cells);
        }

        return implode("\n", $out);
    }

    /**
     * Иероглифы в русском ответе.
     *
     * Найдено на приёмке 04.09.2026: «Если эта цена超出了 ваш бюджет».
     * Это известная манера китайских моделей — ронять в текст иероглиф
     * вместо слова, — и промптом она лечится плохо: модель не замечает,
     * что перешла на другой язык.
     *
     * Выбрасываем, а не эскалируем: ответ в остальном верный и полезный,
     * терять его целиком из-за одного слова хуже, чем отдать предложение
     * с дырой. Но факт пишется в лог: если это станет частым, лечить надо
     * сменой модели, а не заплаткой.
     */
    private function dropForeignScript(string $text): string
    {
        // CJK: иероглифы, кана, хангыль. Кириллица, латиница и знаки
        // препинания не трогаются.
        $pattern = '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]+/u';

        if (preg_match($pattern, $text) !== 1) {
            return $text;
        }

        Log::warning('Assistant reply contained CJK characters', [
            'snippet' => Str::limit($text, 200),
        ]);

        $cleaned = (string) preg_replace($pattern, '', $text);

        // Схлопываем пробелы, оставшиеся на месте выброшенного.
        return trim((string) preg_replace('/[ \t]{2,}/u', ' ', $cleaned));
    }

    /**
     * Прикидка доставки через разницу в цене.
     *
     * Промпт запрещает называть стоимость доставки конкретного заказа, а с
     * 14.09.2026 — дословно и «на разницу в цене как раз выйдет доставка».
     * Запрет не держит: на приёмке это прозвучало дважды — «разница около
     * 8 500 ₽ — примерно на эти деньги и выйдет перевозка», а уже после
     * запрета «разница с предложением за 105 000 руб. может как раз покрыть
     * доставку». Такой довод и уводит покупателя к конкуренту: суммы сведены,
     * выбор объявлен «сопоставимым». Правило, не сработавшее дважды, — в код.
     *
     * Выбрасывается предложение целиком, где рядом стоят «разница» и доставка:
     * заменить в нём нечего, а без него ответ цел. Точка в «руб.» посреди
     * фразы границей не считается — иначе цена разрезала бы довод пополам
     * и он прошёл бы; в конце фразы, перед заглавной, — считается.
     */
    private function dropDeliveryGuess(string $text): string
    {
        if (mb_stripos($text, 'разниц') === false) {
            return $text;
        }

        return $this->dropSentences(
            $text,
            static fn (string $sentence): bool => mb_stripos($sentence, 'разниц') !== false
                && preg_match('/доставк|перевозк/iu', $sentence) === 1,
            'Assistant reply guessed delivery cost from price difference',
        );
    }

    /**
     * Сумма, которую этот посетитель на витрине не видит.
     *
     * Третий слой защиты цены для зарегистрированных, и самый последний.
     * Первый — устройство данных: в ProductCard второго поля с ценой нет,
     * и собрать из карточки текст с членской суммой физически нельзя.
     * Второй — запрет арифметики в промпте. Этот нужен потому, что к сумме
     * можно прийти и мимо данных: посчитать из процента, вспомнить из хода,
     * когда покупатель был в аккаунте, или просто угадать.
     *
     * Выбрасываем предложение, а не весь ответ: остальное — цена, наличие,
     * гарантия — верно и полезно, а без одной фразы ответ цел. Срабатывание
     * пишется в лог: если оно частое, значит правило в промпте не держит,
     * и лечить надо его, а не заплатку.
     *
     * @param  list<int>  $amounts
     */
    private function dropWithheldPrices(string $text, array $amounts): string
    {
        if ($amounts === []) {
            return $text;
        }

        $withheld = array_flip($amounts);

        return $this->dropSentences(
            $text,
            static function (string $sentence) use ($withheld): bool {
                foreach (self::amountsIn($sentence) as $amount) {
                    if (isset($withheld[$amount])) {
                        return true;
                    }
                }

                return false;
            },
            'Assistant named a member-only price to a guest',
        );
    }

    /**
     * Рублёвые суммы, названные в тексте: «96 450 руб.», «96450», «96 450,00».
     *
     * Порог в четыре знака — чтобы «скидка 12%» и «в наличии 3» не считались
     * суммами. Разделителем тысяч бывает и обычный пробел, и неразрывный:
     * первый ставит модель, второй прилетает из наших же чисел.
     *
     * @return list<int>
     */
    private static function amountsIn(string $sentence): array
    {
        $pattern = '/(?<![\d,.])\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?![\d])'
            .'|(?<![\d,.])\d{4,}(?![\d])/u';

        if (preg_match_all($pattern, $sentence, $matches) !== 1 && $matches[0] === []) {
            return [];
        }

        return array_map(
            static fn (string $number): int => (int) preg_replace('/\D/u', '', $number),
            $matches[0],
        );
    }

    /**
     * Выбросить предложения, на которые сработало правило.
     *
     * Точка в «руб.» посреди фразы границей предложения не считается — иначе
     * цена разрезала бы фразу пополам и половина проходила бы фильтр; в конце
     * фразы, перед заглавной, — считается.
     *
     * @param  callable(string): bool  $unwanted
     */
    private function dropSentences(string $text, callable $unwanted, string $logMessage): string
    {
        $mask = "\u{E000}";
        $lines = preg_split('/\R/u', $text) ?: [];
        $dropped = false;

        foreach ($lines as $i => $line) {
            $masked = preg_replace('/\b(руб|тыс|коп|шт|млн)\.(?=\**\s*[\p{Ll}\d«"(])/u', '$1'.$mask, $line) ?? $line;
            $sentences = preg_split('/(?<=[.!?]|[.!?]\*\*)\s+/u', $masked) ?: [$masked];

            $kept = array_filter($sentences, static fn (string $sentence): bool => ! $unwanted($sentence));

            if (count($kept) !== count($sentences)) {
                $dropped = true;
                $lines[$i] = str_replace($mask, '.', implode(' ', $kept));
            }
        }

        if (! $dropped) {
            return $text;
        }

        Log::warning($logMessage, ['snippet' => Str::limit($text, 200)]);

        return implode("\n", $lines);
    }

    /**
     * Обороты, выдающие устройство бота, и то, чем их заменить.
     *
     * Замена, а не вырезание: фраза стоит в середине предложения, и удаление
     * оставило бы обрубок. «В базе знаний магазина указано, что…» превращается
     * в «У нас указано, что…» — грамматика цела, смысл тот же, кухня скрыта.
     */
    private const LEAKS = [
        '/\bсогласно найденной информации\b/iu' => 'по нашим данным',
        '/\bпо данным системы\b/iu' => 'по нашим данным',
        '/\bв результатах поиска\b/iu' => 'у нас',
    ];

    /**
     * @param  list<int>  $withheldPrices  суммы, которых этот посетитель на витрине
     *                                     не видит, — см. dropWithheldPrices()
     */
    public function format(string $text, array $withheldPrices = []): string
    {
        $text = trim($text);

        if ($text === '') {
            return $text;
        }

        $text = $this->hideInternals($text);
        $text = $this->dropForeignScript($text);
        $text = $this->dropDeliveryGuess($text);
        $text = $this->dropWithheldPrices($text, $withheldPrices);
        $text = $this->flattenTables($text);

        // Дыры, оставшиеся от вычищенных оборотов.
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Прячет упоминания внутренней кухни.
     *
     * Промпт это запрещает, и запрет в основном работает — на замере
     * утечка встретилась в 2 ответах из 90. Но встретилась она не случайно:
     * оба раза на вопросе, где боту надо признать, что конкретного факта
     * у магазина нет. Именно там соблазн сказать «в базе знаний этого нет»
     * сильнее всего, и именно там он звучит хуже всего — покупателю нет
     * дела до устройства нашей памяти.
     *
     * Добавление в промпт правильной формулировки снизило частоту, но
     * не убрало: 1 случай из 5 остался. Тот же вывод, что и с markdown —
     * промпт задаёт норму, а гарантирует её код.
     */
    private function hideInternals(string $text): string
    {
        $text = $this->hideKnowledgeBase($text);

        foreach (self::LEAKS as $pattern => $replacement) {
            $text = preg_replace_callback(
                $pattern,
                static fn (array $m): string => self::keepCase($m[0], $replacement),
                $text,
            ) ?? $text;
        }

        return $text;
    }

    /**
     * «База знаний» в любом падеже, с предлогом или без.
     *
     * Раньше здесь была одна строка в LEAKS — ровно «в базе знаний», — и она
     * пропускала всё остальное. 14.09.2026 на приёмке бот написал «В база
     * знаний указано»: модель ошиблась в падеже, а фильтр, сверявший форму
     * буква в букву, промолчал. Перебор тогда же показал, что так же проходили
     * «по базе знаний», «из базы знаний», «согласно базе знаний» и «проверил
     * базу знаний» — промпт всё это запрещает, но запрет не держит.
     *
     * Поэтому основа и окончание разобраны раздельно: предлог решает, чем
     * заменить, а окончание «базы» — в каком падеже подставить «наши данные».
     * Правила идут от частного к общему, без предлога — последним.
     */
    private function hideKnowledgeBase(string $text): string
    {
        if (mb_stripos($text, 'знани') === false) {
            return $text;
        }

        // Притяжательное уходит вместе с «базой»: «в нашей базе знаний» →
        // «у нас», а не «в нашей у нас».
        $possessive = '(?:(?:моя|мою|моей|моим|моих|наша|нашу|нашей|нашим|наших|'
            .'своя|свою|своей|своим|своих|эта|эту|этой|этим|этих)\s+)?';
        $kb = $possessive.'(баз[а-яё]*)\s+знани[а-яё]*(?:\s+(?:магазина|сайта))?\b';

        $rules = [
            '/\bво?\s+'.$kb.'/iu' => static fn (array $m): string => 'у нас',
            '/\b(?:судя\s+по|согласно|по|из)\s+'.$kb.'/iu' => static fn (array $m): string => 'по нашим данным',
            '/\bко?\s+'.$kb.'/iu' => static fn (array $m): string => 'к нашим данным',
            '/\b'.$kb.'/iu' => static fn (array $m): string => self::ourDataInCaseOf($m[1]),
        ];

        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace_callback(
                $pattern,
                static fn (array $m): string => self::keepCase($m[0], $replacement($m)),
                $text,
            ) ?? $text;
        }

        return $text;
    }

    /** «Наши данные» в том падеже, в каком стояла «база». */
    private static function ourDataInCaseOf(string $base): string
    {
        $base = mb_strtolower($base);

        return match (true) {
            str_ends_with($base, 'ой'), str_ends_with($base, 'ою'), str_ends_with($base, 'ами') => 'нашими данными',
            str_ends_with($base, 'ам') => 'нашим данным',
            str_ends_with($base, 'ы'), str_ends_with($base, 'е'), str_ends_with($base, 'ах') => 'наших данных',
            default => 'наши данные',
        };
    }

    /** Заглавная буква в начале предложения должна пережить замену. */
    private static function keepCase(string $matched, string $replacement): string
    {
        $first = mb_substr($matched, 0, 1);

        return mb_strtoupper($first) === $first
            ? mb_strtoupper(mb_substr($replacement, 0, 1)).mb_substr($replacement, 1)
            : $replacement;
    }
}
