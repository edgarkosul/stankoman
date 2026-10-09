<?php

namespace App\Services\Catalog;

use App\Services\Ai\Data\SpecCondition;

/**
 * Проверка строк характеристик товара на порог — грубая и честная.
 *
 * Характеристики приходят от десятка поставщиков, и одно и то же записано
 * по-разному: «Производитель­ность, л/мин = 1090» (с невидимым мягким
 * переносом в слове), «Сварочный ток = 50 - 175, А», «90° (мм) = 400 / 550х360»,
 * «Масса нетто/брутто = 239 / 269 кг». Понять составное значение до конца
 * («400» здесь круг, «550х360» — прямоугольник) правилом нельзя, поэтому
 * класс делает ровно то, что можно сделать надёжно:
 *
 *   - находит строки по словам названия (вариантов несколько — «или»);
 *   - достаёт из значения числа, выбрасывая углы («90° - 500х500» — это 500 и 500);
 *   - переводит единицы внутри одной величины (Вт↔кВт, л/мин↔м³/мин, мм↔м);
 *   - говорит «прошло», если порог проходит хоть одно число строки
 *     (или каждое — когда так задано условием).
 *
 * Окончательное решение — за моделью: строка уходит к ней дословно. Задача
 * класса — не отсечь лишнего и честно посчитать, у кого строки нет вовсе.
 */
final class SpecMatcher
{
    /**
     * Единицы: запись => [величина, множитель к базовой единице величины].
     *
     * Порядок важен: длинные записи раньше коротких, иначе «м3/мин» прочлось
     * бы как «м», а «л/мин» — как «л».
     */
    private const UNITS = [
        'м3/мин' => ['flow', 1000.0],
        'м3/ч' => ['flow', 1000 / 60],
        'л/мин' => ['flow', 1.0],
        'л/ч' => ['flow', 1 / 60],
        'л/с' => ['flow', 60.0],
        'об/мин' => ['rpm', 1.0],
        'м/мин' => ['speed', 1.0],
        'м/с' => ['speed', 60.0],
        'м2/ч' => ['area_rate', 1.0],
        'кгс/см2' => ['pressure', 0.980665],
        'л.с.' => ['power', 735.5],
        'квт' => ['power', 1000.0],
        'вт' => ['power', 1.0],
        'мпа' => ['pressure', 10.0],
        'кпа' => ['pressure', 0.01],
        'бар' => ['pressure', 1.0],
        'атм' => ['pressure', 1.01325],
        'psi' => ['pressure', 0.0689476],
        'м3' => ['volume', 1000.0],
        'мл' => ['volume', 0.001],
        'л' => ['volume', 1.0],
        'м2' => ['area', 1.0],
        'мм' => ['length', 1.0],
        'см' => ['length', 10.0],
        'м' => ['length', 1000.0],
        'кг' => ['mass', 1.0],
        'г' => ['mass', 0.001],
        'т' => ['mass', 1000.0],
        'кв' => ['voltage', 1000.0],
        'в' => ['voltage', 1.0],
        'а' => ['current', 1.0],
        'гц' => ['frequency', 1.0],
    ];

    /** Латиница и разговорные записи — к одной форме. */
    private const ALIASES = [
        'kw' => 'квт', 'w' => 'вт', 'mm' => 'мм', 'cm' => 'см', 'kg' => 'кг', 'bar' => 'бар',
        'l/min' => 'л/мин', 'v' => 'в', 'a' => 'а', 'hz' => 'гц', 'mpa' => 'мпа',
        'куб/мин' => 'м3/мин', 'кубов/мин' => 'м3/мин', 'куб.м/мин' => 'м3/мин',
        'куб/ч' => 'м3/ч', 'м3/час' => 'м3/ч', 'л/час' => 'л/ч', 'литров' => 'л', 'литр' => 'л',
        'тонн' => 'т', 'тонна' => 'т', 'тонны' => 'т', 'метр' => 'м', 'метра' => 'м', 'метров' => 'м',
        'ампер' => 'а', 'вольт' => 'в', 'атмосфер' => 'атм', 'кгс/см' => 'кгс/см2', 'л.с' => 'л.с.',
    ];

    /**
     * Проверка одного условия по строкам характеристик одного товара.
     *
     * @param  list<array{name: string, value: string}>  $rows
     *                                                          `values` — числа проверенных строк уже в единице покупателя: по ним
     *                                                          ищутся ближайшие к порогу, когда не подошло ничего.
     * @return array{status: 'pass'|'fail'|'nodata', lines: list<array{name: string, value: string}>, values: list<float>}
     */
    public function check(SpecCondition $condition, array $rows): array
    {
        $wanted = self::unit($condition->unit);
        $keepDegrees = $condition->unit !== '' && preg_match('/°|град/u', $condition->unit) === 1;
        $lines = [];
        $values = [];
        $judged = false;
        $passed = false;

        foreach ($rows as $row) {
            if (! self::nameMatches($row['name'], $condition->names)) {
                continue;
            }

            $lines[] = ['name' => $row['name'], 'value' => $row['value']];
            $numbers = self::numbers($row['value'], $keepDegrees);

            if ($numbers === []) {
                continue;
            }

            $factor = self::factor(self::unitOfRow($row['name'], $row['value']), $wanted);

            // Другая величина — «производительность» поломоечной машины в м²/ч
            // под условие в л/мин: строка есть, но сравнивать её не с чем.
            if ($factor === null) {
                continue;
            }

            $numbers = array_map(static fn (float $n): float => $n * $factor, $numbers);
            $values = [...$values, ...$numbers];
            $within = array_map(fn (float $n): bool => $this->within($n, $condition), $numbers);

            $judged = true;
            $passed = $passed || ($condition->everyNumber ? ! in_array(false, $within, true) : in_array(true, $within, true));
        }

        return [
            'status' => match (true) {
                $passed => 'pass',
                $judged => 'fail',
                default => 'nodata',
            },
            'lines' => $lines,
            'values' => $values,
        ];
    }

    /**
     * Насколько число строки не дотянуло до порога: 0 — подходит, больше — дальше.
     *
     * Для «ближайших к порогу», когда под условие не подошло ничего: на
     * «домкрат на 1000 тонн» правильный ответ — «нет, самые мощные — 300 т»,
     * а не «200 т» из случайной пятёрки по словам (09.10.2026).
     *
     * @param  list<float>  $values
     */
    public static function distance(SpecCondition $condition, array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $best = null;

        foreach ($values as $value) {
            $gap = max(0.0, ($condition->min ?? $value) - $value, $value - ($condition->max ?? $value));
            $best = $best === null ? $gap : min($best, $gap);
        }

        return $best;
    }

    /**
     * Строка подходит, если в её названии есть ВСЕ слова хоть одного варианта.
     *
     * Сравнение по основе слова (минус два знака у длинных), как у поиска
     * разделов: «производительность» находит и «Производитель­ность, л/мин»,
     * и «Производительность: л/мин». Числа сравниваются целиком — «90»
     * в варианте отличает «90° (мм)» от «45° (мм)».
     *
     * @param  list<string>  $variants
     */
    public static function nameMatches(string $name, array $variants): bool
    {
        $name = self::clean($name);

        foreach ($variants as $variant) {
            $stems = self::stems($variant);

            if ($stems === []) {
                continue;
            }

            $all = true;

            foreach ($stems as $stem) {
                if (! str_contains($name, $stem)) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * Есть ли в названии хоть одно слово из вариантов — «похожая» строка.
     *
     * Нужна подсказке, а не отбору: модель искала «ширину строгания», а у
     * половины фуговальных станков та же величина записана «Ширина реза».
     * Общее слово выводит такие названия в начало списка, который ей
     * показывают, — решает, та ли это характеристика, она сама.
     *
     * @param  list<string>  $variants
     */
    public static function sharesWord(string $name, array $variants): bool
    {
        $name = self::clean($name);

        foreach ($variants as $variant) {
            foreach (self::stems($variant) as $stem) {
                if (mb_strlen($stem) >= 4 && str_contains($name, $stem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Числа значения — без углов и без цифр, которые принадлежат единице.
     *
     * Тысячи через пробел склеиваются, только когда число в значении одно:
     * «15 000» — это пятнадцать тысяч, а «400 400х400» (REALREZ так пишет
     * круг и квадрат подряд) — три числа, и склейка дала бы 400 400.
     *
     * @return list<float>
     */
    public static function numbers(string $value, bool $keepDegrees = false): array
    {
        $value = self::clean($value);

        if (preg_match('/^\s*\d{1,3}(?: \d{3})+(?:[.,]\d+)?\s*[^\d]*$/u', $value) === 1) {
            $value = (string) preg_replace('/(?<=\d) (?=\d{3})/u', '', $value);
        }

        if (! $keepDegrees) {
            $value = (string) preg_replace('/[+\-−]?\d+(?:[.,]\d+)?\s*°/u', ' ', $value);
        }

        // «м3», «м²», «кгс/см2»: цифра здесь часть единицы, а не значение.
        $value = (string) preg_replace('/(?<=м)[23](?![\d.,])/u', '', $value);

        preg_match_all('/\d+(?:[.,]\d+)?/u', $value, $matches);

        return array_map(static fn (string $n): float => (float) str_replace(',', '.', $n), $matches[0]);
    }

    /**
     * Единица строки: сначала из значения (число и единица рядом —
     * «60 л», «175, А»), потом из хвоста названия («Давление, бар»,
     * «Мощность двигателя (кВт)»). В середине названия единицу не ищем:
     * «в» и «а» там — предлог и союз, а не вольты с амперами.
     */
    public static function unitOfRow(string $name, string $value): ?string
    {
        $value = (string) preg_replace('/\s*\/\s*/u', '/', self::clean($value));

        foreach (array_keys(self::UNITS) as $unit) {
            $pattern = '/\d\s*,?\s*'.preg_quote($unit, '/').'(?![\p{L}\d\/])/u';

            if (preg_match($pattern, self::canonicalText($value)) === 1) {
                return $unit;
            }
        }

        if (preg_match('/[,(:]\s*([^,():]+?)\s*\)?\s*$/u', self::clean($name), $tail) === 1) {
            return self::unit($tail[1]);
        }

        return null;
    }

    /**
     * Единица, названная словами: «м³/мин», «кВт», «тонн» — к записи из UNITS.
     * Незнакомая — null: тогда числа сравниваются как есть.
     */
    public static function unit(string $text): ?string
    {
        $text = self::canonicalText(self::clean($text));
        $text = (string) preg_replace('/\s*\/\s*/u', '/', trim($text, " .\t"));

        $text = self::ALIASES[$text] ?? $text;

        return isset(self::UNITS[$text]) ? $text : null;
    }

    /**
     * Во сколько раз умножить число строки, чтобы получить единицу покупателя.
     *
     * Единица строки неизвестна — считаем, что та же: это частый случай
     * («Ширина реза = 203» без «мм»), и модель всё равно видит строку целиком.
     * Величины разные — null: сравнивать нельзя.
     */
    public static function factor(?string $from, ?string $to): ?float
    {
        if ($to === null || $from === null) {
            return 1.0;
        }

        [$fromKind, $fromScale] = self::UNITS[$from];
        [$toKind, $toScale] = self::UNITS[$to];

        return $fromKind === $toKind ? $fromScale / $toScale : null;
    }

    private function within(float $number, SpecCondition $condition): bool
    {
        $eps = 1e-9;

        return ($condition->min === null || $number >= $condition->min - $eps)
            && ($condition->max === null || $number <= $condition->max + $eps);
    }

    /**
     * Нижний регистр, «ё», мягкий перенос (U+00AD сидит внутри
     * «Производитель­ность» у части поставщиков и ломает любое сравнение),
     * неразрывные пробелы, градус, который MetalTec пишет знаком «˚»,
     * и твёрдый знак, который поставщики путают с мягким.
     */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{00AD}", "\u{00A0}", "\u{202F}", "\t", '˚', 'º'], ['', ' ', ' ', ' ', '°', '°'], $text);

        // «Грузоподьемность» через мягкий знак — так записано у всех 89
        // гидравлических домкратов, и «грузоподъемность» с ней не совпадала.
        return str_replace(['ё', 'ъ'], ['е', 'ь'], mb_strtolower($text));
    }

    /** «м³» и «м²» пишут и надстрочной цифрой, и обычной. */
    private static function canonicalText(string $text): string
    {
        return str_replace(['³', '²'], ['3', '2'], $text);
    }

    /**
     * @return list<string>
     */
    private static function stems(string $variant): array
    {
        $stems = [];

        foreach (preg_split('/[^\p{L}\p{N}°]+/u', self::clean($variant)) ?: [] as $word) {
            $length = mb_strlen($word);

            $stems[] = match (true) {
                $word === '' => null,
                preg_match('/^\d/u', $word) === 1 => $word,
                $length >= 5 => mb_substr($word, 0, $length - 2),
                $length >= 3 => $word,
                default => null,
            };
        }

        return array_values(array_filter($stems, static fn (?string $stem): bool => $stem !== null));
    }
}
