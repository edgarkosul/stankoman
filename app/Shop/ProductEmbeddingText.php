<?php

namespace App\Shop;

use App\Models\Product;
use App\Services\Ai\Support\ProductTextExtractor;
use App\Support\Products\ProductSpecs;

/**
 * Текст товара, уходящий в вектор.
 *
 * Один вектор на товар, а не по фрагменту на абзац, — решение донора
 * по замеру 04.09.2026: при пороге разбиения в 1800 знаков выходит 1.23
 * фрагмента на товар, то есть 82% каталога умещается в один. Фрагменты
 * стоили бы отдельного индекса, дублирования фильтров в каждом фрагменте
 * и дедупликации по товару на выдаче — ради хвостов маркетингового текста.
 *
 * ПОРЯДОК ЧАСТЕЙ — не косметика, а следствие потолка. Текст обрезается
 * с конца, поэтому первым идёт то, что отличает товар от соседа по полке:
 * название, бренд, раздел каталога, характеристики. Описание последним:
 * его начало у наших карточек — та же таблица характеристик своими словами
 * (для смысла это полезно), дальше проза, которая у соседних товаров одной
 * серии почти одинакова.
 *
 * РАЗДЕЛ КАТАЛОГА ВХОДИТ В ТЕКСТ намеренно. «Ленточнопильные станки»
 * и «Аспирация» — слова, которых в карточке может не быть вовсе, а покупатель
 * приходит именно с ними. Классификацию магазин вёл руками, и отдать её
 * вектору — самое дешёвое, что можно сделать для качества подбора.
 *
 * Цена и наличие в текст НЕ входят: они меняются ежедневно (ночной пересчёт
 * курсов двигает цены пачками по всему каталогу), а каждое изменение текста —
 * это оплаченный вызов эмбеддинга. Фильтровать по ним умеет сам Meilisearch,
 * а называет их бот из базы, живыми.
 */
final class ProductEmbeddingText
{
    /**
     * Путь раздела по его id.
     *
     * `ancestorsAndSelf()` идёт к корню по одному запросу на предка, а команда
     * зовёт его на каждый из трёх с половиной тысяч товаров. Разделов при этом
     * полторы сотни, и путь у них один и тот же.
     *
     * @var array<int, string>
     */
    private array $paths = [];

    public function __construct(
        private readonly ProductTextExtractor $extractor,
        private readonly ProductSpecs $specs,
        private readonly int $limit,
    ) {}

    public function for(Product $product): string
    {
        $parts = [];

        $parts[] = trim((string) $product->name);

        if (filled($product->brand)) {
            $parts[] = 'Бренд: '.trim((string) $product->brand);
        }

        $category = $product->primaryCategory();

        if ($category !== null) {
            $id = (int) $category->getKey();
            $parts[] = 'Раздел: '.($this->paths[$id] ??= $category->ancestorsAndSelf()->pluck('name')->implode(' › '));
        }

        $specs = [];

        // Характеристики — из колонки `specs`: EAV у нас покрывает треть
        // каталога, а JSON заполнен у 99,8% товаров.
        foreach ($this->specs->rows($product) as $row) {
            $specs[] = $row['name'].': '.$row['value'];
        }

        if ($specs !== []) {
            $parts[] = implode('; ', $specs);
        }

        // Описание уже разобранное: таблицы стали строками «название:
        // значение», разметка и картинки выброшены.
        $description = $this->extractor->toText($product->description, $this->limit);

        if ($description !== '') {
            $parts[] = $description;
        }

        return $this->cut(implode("\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * Отпечаток текста. По нему ночная команда решает, тратить ли вызов
     * шлюза: совпал — товар пропускается.
     */
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Общий потолок. Отдельный от потолка описания: описание может
     * уместиться целиком, а вместе с характеристиками и разделом
     * перевалить за предел.
     */
    private function cut(string $text): string
    {
        if (mb_strlen($text) <= $this->limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $this->limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false && $lastSpace > $this->limit * 0.6
            ? mb_substr($cut, 0, $lastSpace)
            : $cut);
    }
}
