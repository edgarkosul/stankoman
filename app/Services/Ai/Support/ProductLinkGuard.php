<?php

namespace App\Services\Ai\Support;

use App\Services\Catalog\CatalogQueryShape;

/**
 * Названный товар не остаётся без ссылки.
 *
 * ЧЕТВЁРТАЯ попытка добиться одного и того же. Правило было в промпте,
 * потом приписками в шапке поисковой выдачи, потом имя товара стало
 * приходить готовой ссылкой — и всё равно 04.09.2026 замер показал ответ,
 * где пять перфораторов названы с ценами и сроками гарантии и ни одного
 * адреса: модель перебрала имена своими словами, а разметку потеряла.
 *
 * Цена этого дефекта высокая и вся ложится на покупателя: он не может
 * ни посмотреть фотографии, ни положить товар в корзину, и идёт искать
 * по названию то, что бот ему уже нашёл. Часть таких покупателей не
 * возвращается.
 *
 * ПОЧЕМУ ДОПИСЫВАЕМ, А НЕ ПРАВИМ ВНУТРИ. Вшить ссылку в середину чужого
 * предложения — значит угадывать границы имени в тексте, который модель
 * написала как хотела: «**Перфоратор АКМ1815** (Энкор)». Ошибка такой
 * правки ломает ответ на глазах у покупателя. Список внизу не ломает
 * ничего и решает ровно ту задачу, ради которой ссылка нужна: чтобы
 * было куда нажать.
 *
 * ОПОЗНАЁМ ПО ОБОЗНАЧЕНИЮ, а не по имени целиком. Имя модель пересказывает
 * («Перфоратор акк. Энкор ПА-14,4ЭР/10Л LiIon кейс» → «Перфоратор Энкор
 * ПА-14,4ЭР»), а обозначение переписывает точно: в нём цифры, и ошибиться
 * в нём страшно. Товар без обозначения в имени («Компрессор поршневой»)
 * пропускается — лучше не дописать ссылку, чем дописать чужую.
 */
final class ProductLinkGuard
{
    /**
     * @param  list<array{name: string, url: string}>  $products  показанные в этом ходе
     */
    public function ensure(string $text, array $products): string
    {
        if (trim($text) === '' || $products === []) {
            return $text;
        }

        $core = CatalogQueryShape::core($text);
        $missing = [];

        foreach ($products as $product) {
            $url = trim($product['url'] ?? '');
            $name = trim($product['name'] ?? '');

            if ($url === '' || $name === '' || str_contains($text, $url)) {
                continue;
            }

            $designation = $this->designation($name);

            if ($designation === null) {
                continue;
            }

            if (str_contains($core, CatalogQueryShape::core($designation))) {
                $missing[$url] = '- ['.$name.']('.$url.')';
            }
        }

        if ($missing === []) {
            return $text;
        }

        return rtrim($text)."\n\n".implode("\n", $missing);
    }

    /**
     * Обозначение модели внутри имени.
     *
     * Сначала ищется одно слово, где есть и буква, и цифра, — так устроено
     * большинство обозначений: «АКМ1815», «ЗП-1100ЭК», «ЗПМ-40-1100».
     *
     * Если такого нет, берётся пара соседних слов, где одно латиницей,
     * а другое с цифрой: «ВК-J 15/10», «Expert 102». Серии часто пишут
     * именно так, и по отдельности половинки не годятся — «15/10» найдётся
     * в любом тексте с дробью, «ВК-J» без цифр опознаёт всю линейку.
     *
     * Порог в четыре знака отсекает мусор: «10Л», «TG», «220».
     */
    private function designation(string $name): ?string
    {
        $words = [];

        foreach (preg_split('/\s+/u', $name) ?: [] as $word) {
            $word = trim($word, " \t\n\r\0\x0B()«»\",.;:");

            if ($word !== '') {
                $words[] = $word;
            }
        }

        foreach ($words as $word) {
            if (mb_strlen($word) >= 4
                && preg_match('/\d/u', $word) === 1
                && preg_match('/\p{L}/u', $word) === 1) {
                return $word;
            }
        }

        for ($i = 0; $i < count($words) - 1; $i++) {
            [$left, $right] = [$words[$i], $words[$i + 1]];

            $latin = preg_match('/[A-Za-z]/', $left) === 1 && preg_match('/\d/u', $left) !== 1;
            $digits = preg_match('/\d/u', $right) === 1;

            if ($latin && $digits && mb_strlen(CatalogQueryShape::core($left.$right)) >= 5) {
                return $left.$right;
            }
        }

        return null;
    }
}
