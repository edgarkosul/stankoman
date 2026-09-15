<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\CatalogSection;
use App\Services\Ai\Data\ProductCard;
use App\Services\Ai\Data\ProductMatches;
use App\Services\Ai\Data\ProductQuery;

/**
 * Каталог магазина глазами ассистента.
 *
 * Шов между инструментами бота и моделями магазина. Инструменты
 * (App\Services\Ai\Tools) не знают ни Product, ни Category, ни того, как
 * магазин решает, кому показывать скидку, — это знает реализация
 * (App\Shop\EloquentProductLookup).
 *
 * У kratonshop шва нет: там инструменты читают модель напрямую, и ценовая
 * политика донора — скидка открывается гостю ПО ТОВАРУ (`show_discount_to_guests`)
 * — зашита в текст инструмента. Здесь политика другая: скидку видит
 * ПОСЕТИТЕЛЬ, вошедший в аккаунт (DiscountVisibility). Перенести текст
 * инструмента значило бы перенести чужое правило, и ошибка вышла бы
 * ровно в деньгах.
 *
 * Главное обещание контракта — в ProductCard: цена в карточке уже та,
 * что видит этот посетитель, и второй цены в ней нет.
 */
interface ProductLookup
{
    /**
     * Один товар по адресу карточки или артикулу — с описанием.
     *
     * Не нашёлся точно — второй заход по обозначению модели: покупатель
     * называет машину так, как она написана на шильдике, а не артикулом.
     */
    public function find(string $sku, string $slug, bool $seesDiscounts): ?ProductCard;

    /**
     * Товары по запросу, в порядке релевантности, — и то, как они найдены:
     * без каких слов собрана выдача, если без каких-то пришлось. Описание
     * есть только у товара, который покупатель назвал по обозначению.
     */
    public function search(ProductQuery $query): ProductMatches;

    /**
     * Листовые разделы каталога по слову покупателя, от самого подходящего.
     *
     * @return list<CatalogSection>
     */
    public function sections(string $query, int $limit): array;

    /**
     * Бренды активных товаров — так, как они записаны в карточках.
     *
     * @return list<string>
     */
    public function brands(): array;

    /**
     * Цены для зарегистрированных, которые гость на витрине не видит.
     *
     * Только для выходной проверки ответа (ReplyFormatter): в текст для модели
     * эти числа не попадают никогда. Нужны на случай, когда модель доберётся
     * до суммы сама — из прошлого хода, где покупатель ещё был в аккаунте,
     * или арифметикой над процентом.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public function memberPrices(array $productIds): array;
}
