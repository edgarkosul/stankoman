<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Contracts\ProductLookup;
use App\Services\Ai\Data\CatalogSection;
use App\Services\Ai\Data\ProductCard;
use App\Services\Ai\Data\SpecCondition;
use App\Services\Ai\Data\SpecMatches;
use App\Services\Ai\Data\SpecQuery;
use App\Services\Catalog\SpecMatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Подбор по порогу характеристики: «компрессор от 3 кубов», «домкрат на 100
 * тонн до 60 кг», «пила под заготовку 400×400».
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ИНСТРУМЕНТ. search_products отдаёт пять товаров по
 * совпадению слов, а порог по числу — вопрос ко ВСЕМУ разделу. Замер
 * 09.10.2026, одиннадцать вопросов с порогом из разных разделов, по два
 * прогона: в трёх ответах из 22 бот сказал «подходящих не нашлось» при 9–30
 * подходящих, и только в одном назвал хоть одну из трёх самых дешёвых
 * подходящих моделей. Чинить это подсказками под конкретный параметр
 * бесполезно: следующий вопрос будет про другой.
 *
 * ПОЧЕМУ ТЕПЕРЬ МОЖНО, хотя в SearchProductsTool отбор по характеристике
 * убран намеренно. Довод там — про EAV, который покрывает треть каталога.
 * Здесь читается колонка `specs` (та же, что вкладка характеристик): она
 * заполнена у 3 994 товаров из 4 001, а нужный параметр внутри раздела —
 * у 90–100%. И главное — чего не проверить, выдача называет вслух.
 *
 * Разбор строк грубый (App\Services\Catalog\SpecMatcher): хоть одно число
 * строки прошло порог — товар в выдаче, а строка уходит модели дословно,
 * и сверяет её она. Так в выдачу не попадает лишнее, а «400 / 550х360»
 * под заготовку 400×400 отсеивает модель, видя строку целиком.
 */
final class FindBySpecTool implements AssistantTool
{
    /** Сколько подошедших показывать за раз; следующие — тем же вызовом с price_min. */
    private const LIMIT = 10;

    private const SECTIONS = 4;

    private const MAX_CONDITIONS = 3;

    private const MAX_NAMES = 8;

    /**
     * Доля товаров без нужной строки, после которой выдача показывает, как
     * характеристики называются в разделе. Ниже — названия угаданы, и список
     * был бы лишними токенами.
     */
    private const NAMES_WHEN_MISSING = 0.3;

    private const NAMES_SHOWN = 40;

    public function __construct(private readonly ProductLookup $products) {}

    public function name(): string
    {
        return 'find_by_spec';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Подобрать товары по ПОРОГУ характеристики: «от 3 кубов в минуту», '
                    .'«не больше 60 кг», «ширина уборки от 50 см», «пропил под заготовку 400×400». '
                    .'Просматривает ВСЕ товары разделов этого вида техники и отбирает по числу '
                    .'в строках характеристик, от дешёвых к дорогим. search_products для такого '
                    .'вопроса не годится: он отдаёт пять товаров по словам и порог не проверяет. '
                    .'Выдача говорит, у скольких товаров нужной строки нет, а если её нет у многих — '
                    .'как характеристики называются в этих разделах: тогда повтори вызов с этими '
                    .'названиями в names.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'kind' => [
                            'type' => 'string',
                            'description' => 'Вид техники со ВСЕМИ уточнениями, что назвал покупатель: '
                                .'«передвижной компрессор», «токарный станок по металлу», «ленточнопильный '
                                .'станок» — не «компрессор» и не «токарный станок». По этим словам выбираются '
                                .'разделы, и без уточнения в подбор попадёт чужое — станки по дереву вместо '
                                .'станков по металлу.',
                        ],
                        'category_id' => [
                            'type' => 'integer',
                            'description' => 'Раздел, который покупатель выбрал из показанных browse_categories.',
                        ],
                        'conditions' => [
                            'type' => 'array',
                            'description' => 'Условия, от одного до трёх; товар подходит, если прошёл все.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'names' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                        'description' => 'Как эта характеристика может называться на карточке — '
                                            .'несколько вариантов: «производительность», «расход воздуха». '
                                            .'Строка подходит, если в её названии есть все слова варианта.',
                                    ],
                                    'min' => ['type' => 'number', 'description' => 'Не меньше.'],
                                    'max' => ['type' => 'number', 'description' => 'Не больше.'],
                                    'unit' => [
                                        'type' => 'string',
                                        'description' => 'В чём покупатель назвал число: кВт, Вт, л/мин, м³/мин, м³/ч, '
                                            .'бар, МПа, атм, л, м³, мм, см, м, кг, т, А, В, Гц, об/мин. Пересчёт '
                                            .'в единицы карточки делает инструмент — не переводи сам.',
                                    ],
                                    'every_number' => [
                                        'type' => 'boolean',
                                        'description' => 'Порог обязано пройти КАЖДОЕ число строки, а не хоть одно. '
                                            .'Для размеров «А×Б», когда важны обе стороны: заготовка 400×400.',
                                    ],
                                ],
                                'required' => ['names'],
                            ],
                        ],
                        'in_stock' => ['type' => 'boolean', 'description' => 'Только то, что есть в наличии.'],
                        'price_min' => ['type' => 'integer', 'description' => 'Цена от, руб. Следующая страница выдачи — тоже через неё.'],
                        'price_max' => ['type' => 'integer', 'description' => 'Цена до, руб.'],
                    ],
                    'required' => ['kind', 'conditions'],
                ],
            ],
        ];
    }

    public function run(array $arguments, ToolContext $context): string
    {
        if ($context->alreadyCalled($this->name(), $arguments)) {
            return 'Такой вызов в этом ходе уже был, и повтор ничего нового не вернёт. '
                .'Ответь покупателю по тому, что уже нашлось, или измени условия.';
        }

        $conditions = $this->conditions($arguments['conditions'] ?? null);

        if (is_string($conditions)) {
            return $conditions;
        }

        $kind = trim((string) ($arguments['kind'] ?? ''));
        $categoryId = is_numeric($arguments['category_id'] ?? null) ? (int) $arguments['category_id'] : null;

        if ($kind === '' && $categoryId === null) {
            return 'Не названо, какую технику подбирать. Передай вид техники в kind.';
        }

        try {
            $sections = $categoryId === null ? self::sectionsOfKind($this->products->sections($kind, self::SECTIONS), $kind) : [];

            if ($categoryId === null && $sections === []) {
                return 'Разделов с такой техникой не нашлось по слову «'.$kind.'». Назови вид техники '
                    .'так, как он пишется в названиях товаров, или найди раздел через browse_categories.';
            }

            $priceMin = is_numeric($arguments['price_min'] ?? null) ? (int) $arguments['price_min'] : null;
            $priceMax = is_numeric($arguments['price_max'] ?? null) ? (int) $arguments['price_max'] : null;

            $matches = $this->products->bySpec(new SpecQuery(
                conditions: $conditions,
                sectionIds: array_map(static fn (CatalogSection $section): int => $section->id, $sections),
                categoryId: $categoryId,
                inStockOnly: ($arguments['in_stock'] ?? null) === true,
                priceMin: $priceMin,
                priceMax: $priceMax,
                seesDiscounts: $context->seesDiscounts,
                limit: self::LIMIT,
            ));
        } catch (Throwable $e) {
            Log::warning('Подбор по характеристикам для ассистента не ответил', ['error' => $e->getMessage()]);

            return 'Подбор по характеристикам временно недоступен. Поищи через search_products '
                .'и не утверждай, что подходящего нет.';
        }

        $shown = [...$matches->cards, ...$matches->nearest];

        foreach ($shown as $card) {
            $context->noteCard($card);
        }

        if (! $context->seesDiscounts && $shown !== []) {
            $context->withholdPrices($this->products->memberPrices(array_map(
                static fn (ProductCard $card): int => $card->id,
                $shown,
            )));
        }

        // Текущий вызов записывается после выполнения: здесь видны только прошлые.
        $retried = in_array($this->name(), $context->calledToolNames(), true);

        return $this->describe($matches, $conditions, $sections, $categoryId, $arguments, $retried);
    }

    /**
     * Только разделы, в названии которых есть все слова вида техники, — если такие есть.
     *
     * Поиск разделов отдаёт и «по названию», и «по найденным товарам»: на
     * «токарный станок по металлу» рядом с «Токарными станками по металлу»
     * приходят «Токарные станки по дереву». Для поиска по словам это просто
     * фильтр пошире, а подбор просматривает разделы ЦЕЛИКОМ — и 09.10.2026
     * бот предложил станок по дереву на вопрос про металл. Ни один раздел
     * не назван словами вида техники — берём все: лучше шире, чем пусто.
     *
     * @param  list<CatalogSection>  $sections
     * @return list<CatalogSection>
     */
    private static function sectionsOfKind(array $sections, string $kind): array
    {
        $named = array_values(array_filter(
            $sections,
            static fn (CatalogSection $section): bool => SpecMatcher::nameMatches(
                (string) preg_replace('/^.*›\s*/u', '', $section->path),
                [$kind],
            ),
        ));

        return $named !== [] ? $named : $sections;
    }

    /**
     * @return list<SpecCondition>|string условия или текст ошибки для модели
     */
    private function conditions(mixed $raw): array|string
    {
        if (! is_array($raw) || $raw === []) {
            return 'Нет условий. Передай в conditions хотя бы одно: названия характеристики и порог.';
        }

        $conditions = [];

        foreach (array_slice(array_values($raw), 0, self::MAX_CONDITIONS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $names = array_values(array_filter(
                array_map(static fn (mixed $name): string => is_scalar($name) ? trim((string) $name) : '', (array) ($item['names'] ?? [])),
                static fn (string $name): bool => $name !== '',
            ));
            $min = is_numeric($item['min'] ?? null) ? (float) $item['min'] : null;
            $max = is_numeric($item['max'] ?? null) ? (float) $item['max'] : null;
            $unit = trim((string) ($item['unit'] ?? ''));

            if ($names === [] || ($min === null && $max === null)) {
                return 'У каждого условия должны быть названия характеристики (names) и порог — min, max или оба.';
            }

            /*
             * Незнакомая единица — отказ, а не сравнение «как есть»: «3» в «кубах
             * в минуту» против «3600» в л/мин прошло бы у всего раздела, и бот
             * уверенно подал бы это как подбор.
             */
            if ($unit !== '' && SpecMatcher::unit($unit) === null) {
                return 'Единица «'.$unit.'» не распознана. Пиши так: кВт, Вт, л/мин, м³/мин, м³/ч, бар, МПа, '
                    .'атм, л, м³, мм, см, м, кг, т, А, В, Гц, об/мин — или переведи число в одну из них.';
            }

            $conditions[] = new SpecCondition(
                names: array_slice($names, 0, self::MAX_NAMES),
                min: $min,
                max: $max,
                unit: $unit,
                everyNumber: ($item['every_number'] ?? null) === true,
            );
        }

        return $conditions === [] ? 'Условия не разобраны: передай names и min или max.' : $conditions;
    }

    /**
     * @param  list<SpecCondition>  $conditions
     * @param  list<CatalogSection>  $sections
     * @param  array<string, mixed>  $arguments
     */
    private function describe(SpecMatches $matches, array $conditions, array $sections, ?int $categoryId, array $arguments, bool $retried): string
    {
        $where = $categoryId !== null
            ? 'раздела №'.$categoryId
            : 'разделов: '.implode('; ', array_map(static fn (CatalogSection $s): string => $s->path, $sections));

        $filters = [];

        if (($arguments['in_stock'] ?? null) === true) {
            $filters[] = 'только в наличии';
        }

        if (is_numeric($arguments['price_min'] ?? null)) {
            $filters[] = 'цена от '.number_format((int) $arguments['price_min'], 0, ',', ' ').' ₽';
        }

        if (is_numeric($arguments['price_max'] ?? null)) {
            $filters[] = 'цена до '.number_format((int) $arguments['price_max'], 0, ',', ' ').' ₽';
        }

        $text = 'Просмотрены ВСЕ '.$matches->scanned.' товаров '.$where
            .($filters !== [] ? ' (фильтры: '.implode(', ', $filters).')' : '').'. '
            .'Условия: '.implode('; ', array_map(static fn (SpecCondition $c): string => $c->label(), $conditions)).".\n"
            .'Подошли по числу: '.$matches->passed.'. Не подошли: '.$matches->failed.'. '
            .'Не проверить — нужной строки или числа в карточке нет: '.$matches->unknown()
            .(count($conditions) > 1 ? ' (по условиям: '.implode(', ', $matches->noData).')' : '').'. '
            .'Такие товары не «отсутствуют в магазине» — у них просто не заполнена эта характеристика.';

        if ($matches->cards === []) {
            $text .= "\nПод условие не подошло ничего"
                .($matches->unknown() === 0 ? ' — скажи это прямо.' : '.');

            if ($matches->nearest !== []) {
                $text .= ' Ближе всего к порогу по этой же характеристике — вот они, самые близкие первыми; '
                    .'называя «ближайшие» или «самые мощные», бери их отсюда, а не из поиска по словам:'
                    ."\n\n".implode("\n", array_map(self::row(...), $matches->nearest));
            }
        } else {
            $shown = count($matches->cards);
            $text .= "\nПоказаны ".$shown.' самых дешёвых из подошедших, от дешёвых к дорогим.';

            if ($matches->passed > $shown) {
                $last = $matches->cards[array_key_last($matches->cards)]->price;
                $text .= $last !== null
                    ? ' Следующие по цене — тот же вызов с price_min = '.($last + 1).'.'
                    : '';
            }

            $text .= "\n\n".implode("\n", array_map(self::row(...), $matches->cards));
        }

        $text .= "\n\nСЛУЖЕБНОЕ, покупателю не пересказывать: отбор по числу грубый — товар попал сюда, "
            .'если порог прошло хоть одно число строки'
            .(array_filter($conditions, static fn (SpecCondition $c): bool => $c->everyNumber) !== []
                ? ' (а где задано «каждое число» — все)' : '')
            .'. Под каждым товаром строки, по которым шёл отбор, дословно. Прежде чем предлагать товар, '
            .'сверь их с условием сам: «400 / 550х360» — это круг 400 и прямоугольник 550×360, а не '
            .'«всё больше 400». Не подошедшее по строке не предлагай. Имя товара переноси в ответ '
            .'вместе со ссылкой.';

        $missing = max([0, ...$matches->noData]);

        if ($matches->names !== [] && $missing > 0 && ($matches->passed === 0 || $missing > $matches->scanned * self::NAMES_WHEN_MISSING)) {
            $text = self::namesHint($matches, $conditions, $retried)."\n\n".$text;
        }

        return $text;
    }

    /**
     * Подсказка, когда нужная строка нашлась не у всех, — ПЕРЕД выдачей.
     *
     * Стоит первой и говорит повелительно не случайно. 09.10.2026 модель
     * искала «ширину строгания» (33 фуговальных станка из 71) и при списке
     * названий в конце выдачи ответила «подошёл один станок»: у 34 других
     * та же величина записана «Ширина реза». Похожие названия — с общим
     * словом — идут в списке первыми.
     *
     * Повторный вызов слышит другое: характеристика у части товаров может
     * быть просто не заполнена, и «повтори ещё» гоняло бы модель до потолка
     * шагов.
     *
     * @param  list<SpecCondition>  $conditions
     */
    private static function namesHint(SpecMatches $matches, array $conditions, bool $retried): string
    {
        $variants = array_merge(...array_map(static fn (SpecCondition $c): array => $c->names, $conditions));
        $related = [];
        $other = [];

        foreach (array_slice($matches->names, 0, self::NAMES_SHOWN) as $name) {
            $line = '«'.$name['name'].'» ('.$name['count'].')';

            if (SpecMatcher::sharesWord($name['name'], $variants)) {
                $related[] = $line;
            } else {
                $other[] = $line;
            }
        }

        $lead = $retried
            ? 'Нужная строка по-прежнему нашлась не у всех ('.$matches->unknown().' из '.$matches->scanned
                .' товаров не проверено). Если в списке ниже есть та же характеристика под другим названием, '
                .'которого ещё не было в names, — повтори с ним; иначе отвечай по тому, что подошло, и скажи, '
                .'что у части раздела эта характеристика в карточке не указана.'
            : 'СНАЧАЛА ПОВТОРИ ВЫЗОВ, потом отвечай: нужная строка нашлась не у всех '
                .'('.$matches->unknown().' из '.$matches->scanned.' товаров не проверено), и подбор по этой выдаче '
                .'неполон. Та же характеристика в этих разделах может называться иначе — добавь подходящие '
                .'названия в names.';

        return $lead.' В скобках — у скольких товаров.'
            .($related !== [] ? "\nПохожие на твою: ".implode('; ', $related).'.' : '')
            .($other !== [] ? "\nОстальные с числами: ".implode('; ', $other).'.' : '');
    }

    private static function row(ProductCard $card): string
    {
        $lines = ['- ['.$card->name.']('.$card->url.') — '.$card->priceLine().'; '.$card->availabilityLine()];

        foreach ($card->specs as $spec) {
            $lines[] = '  · '.$spec['name'].': '.$spec['value'];
        }

        return implode("\n", $lines);
    }
}
