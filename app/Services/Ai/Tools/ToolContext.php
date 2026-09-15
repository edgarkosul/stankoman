<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Data\ProductCard;

/**
 * Состояние одного диалогового хода, доступное инструментам.
 *
 * Кроме входных данных несёт исходящие сигналы: инструмент эскалации не
 * возвращает «эскалировал» текстом — он поднимает здесь флаг, и цикл агента
 * видит его, не разбирая ответ модели строкой.
 */
final class ToolContext
{
    public bool $escalated = false;

    public bool $callbackRequested = false;

    /**
     * Зачем позвали человека — фраза модели для менеджера, а не для
     * покупателя. Уходит в уведомление об эскалации: без неё менеджер
     * открывает диалог, не зная, ради чего его позвали.
     */
    public ?string $escalationReason = null;

    /**
     * Тема обращения. Подставляется в комментарий заявки, чтобы менеджер
     * отвечал, уже понимая вопрос. Контакты через эту тему не проходят —
     * их вводит покупатель в форму.
     */
    public ?string $callbackTopic = null;

    /**
     * Вызовы инструментов по порядку — с аргументами и длительностью.
     *
     * Аргументы здесь не роскошь: когда бот отвечает мимо, причина чаще
     * всего в том, ЧТО он искал, а не в том, что нашёл. По одним именам
     * инструментов «искал „гарантия“» и «искал „гарантия на винтовой
     * компрессор“» неразличимы, а ответы у них разные.
     *
     * Новых персональных данных это не открывает: аргументы модель строит
     * из реплики покупателя, а сама реплика лежит в соседней строке ленты
     * целиком.
     *
     * @var list<array{name: string, arguments: array<string, mixed>, ms: int}>
     */
    public array $calls = [];

    /** @var list<array{chunk_id: string, score: float, title: string, url: ?string}> */
    public array $citations = [];

    /**
     * Лучшая релевантность, которую дал поиск по базе знаний за весь ход.
     * Ниже порога — поднимаем kb_miss. Сигнал слабый (замер показал, что
     * «по теме, но ответа нет» и «ответ есть» по нему не различаются),
     * поэтому трактуем его как «спросили не про магазин», а не как
     * «в базе дыра».
     */
    public ?float $bestScore = null;

    /**
     * Вектор ПЕРВОГО поискового запроса к базе знаний за ход.
     *
     * @var list<float>|null
     */
    public ?array $questionEmbedding = null;

    /**
     * Товары, которые бот в этом ходе видел: имя и адрес карточки.
     *
     * Нужны после ответа, а не во время: по ним проверяется, не назвал ли
     * бот товар без ссылки. Инструменты знают это точно, а разбирать потом
     * готовый текст догадками — гораздо хуже.
     *
     * @var list<array{name: string, url: string}>
     */
    public array $shownProducts = [];

    /**
     * Суммы, стоящие на витрине открыто: цены показанных карточек.
     *
     * @var array<int, true>
     */
    private array $shownPrices = [];

    /**
     * Цены для зарегистрированных, которых этот посетитель не видит.
     *
     * @var array<int, true>
     */
    private array $withheldPrices = [];

    public function __construct(
        /** Ключ сессии для кеша промпта на шлюзе — токен диалога. */
        public readonly ?string $sessionId = null,

        /**
         * Где находится посетитель. Служит ТОЛЬКО для разрешения местоимений
         * («этот», «он»), но не задаёт тему разговора: спросили про доставку,
         * стоя на карточке компрессора, — отвечаем про доставку.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $page = null,

        /**
         * Посетитель вошёл в аккаунт, и витрина показывает ему цену
         * со скидкой. Правило магазина — DiscountVisibility: скидку видит
         * любой авторизованный и не видит ни один гость, товар здесь роли
         * не играет (у kratonshop наоборот — там скидку открывают гостю
         * по товару, и донорский текст инструмента это правило и описывал).
         *
         * Вычисляется на веб-запросе и приезжает в воркер готовым флагом:
         * сессии у воркера нет.
         */
        public readonly bool $seesDiscounts = false,
    ) {}

    /** Запомнить показанный товар. Повторы схлопываются по адресу. */
    public function noteProduct(string $name, string $url): void
    {
        foreach ($this->shownProducts as $shown) {
            if ($shown['url'] === $url) {
                return;
            }
        }

        $this->shownProducts[] = ['name' => $name, 'url' => $url];
    }

    /** Карточка ушла модели: и товар, и его открытая цена. */
    public function noteCard(ProductCard $card): void
    {
        $this->noteProduct($card->name, $card->url);

        if ($card->price !== null) {
            $this->shownPrices[$card->price] = true;
        }
    }

    /**
     * @param  list<int>  $amounts
     */
    public function withholdPrices(array $amounts): void
    {
        foreach ($amounts as $amount) {
            if ($amount > 0) {
                $this->withheldPrices[$amount] = true;
            }
        }
    }

    /**
     * Суммы, которых в ответе быть не должно.
     *
     * Скрытые от гостя цены за вычетом тех, что стоят на витрине открыто:
     * членская цена одного товара может совпасть с обычной ценой соседа
     * по выдаче, и выбрасывать из ответа честную цену соседа нельзя.
     *
     * @return list<int>
     */
    public function pricesToWithhold(): array
    {
        return array_keys(array_diff_key($this->withheldPrices, $this->shownPrices));
    }

    /**
     * Такой вызов в этом ходе уже был.
     *
     * Наблюдение донора 04.09.2026: на «универсальный пылесос до сорока тысяч»
     * модель шесть раз подряд вызвала один и тот же поиск. Каждый вызов —
     * это ещё одно обращение к модели, а результаты предыдущих остаются
     * в контексте, поэтому ход рос квадратично: 0.85 ₽ против 0.06 ₽
     * у обычного, и ответ от этого не улучшился.
     *
     * Запретить повтор можно только здесь, а не промптом: промпт про
     * дисциплину вызовов модель читает, а потом всё равно зацикливается.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function alreadyCalled(string $name, array $arguments): bool
    {
        $fingerprint = self::fingerprint($arguments);

        foreach ($this->calls as $call) {
            if (($call['name'] ?? null) === $name
                && self::fingerprint((array) ($call['arguments'] ?? [])) === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Отпечаток аргументов: регистр и порядок ключей не должны делать
     * повтор «новым вызовом».
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function fingerprint(array $arguments): string
    {
        $normalized = [];

        foreach ($arguments as $key => $value) {
            $normalized[(string) $key] = is_string($value)
                ? mb_strtolower(trim($value))
                : $value;
        }

        ksort($normalized);

        return (string) json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function recordCall(string $name, array $arguments = [], int $ms = 0): void
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments, 'ms' => $ms];
    }

    /**
     * Одни имена — для логов и для «искал в базе знаний» в админке.
     *
     * @return list<string>
     */
    public function calledToolNames(): array
    {
        return array_column($this->calls, 'name');
    }
}
