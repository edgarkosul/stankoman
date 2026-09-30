<?php

namespace App\Services\Ai\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * В ответе остаются только те ссылки, которые принесли инструменты.
 *
 * ЗАЧЕМ. Промпт говорит «ссылку из поиска не выдумывай: либо тот адрес,
 * что пришёл, либо никакого». Правило в основном работает — и всё-таки
 * 29.09.2026, на приёмке базы знаний, модель дважды сочинила адрес
 * страницы: «Подробнее — [Гарантия и сервис](/page/guarantee-service)»
 * и «[Как оформить заказ на сайте](/kak-oformit-zakaz)». Обеих страниц
 * на сайте нет, обе выдуманы по заголовку СТАТЬИ базы знаний, у которой
 * своего адреса нет вовсе.
 *
 * Для покупателя это хуже, чем отсутствие ссылки: он нажимает и получает
 * 404 от магазина, в котором собирался купить станок. Ссылка на своём
 * домене выглядит проверенной, поэтому её и нажимают.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В ПРОМПТЕ. Правило, не сработавшее дважды, переносится
 * в код — как таблицы в `ReplyFormatter` и товарные ссылки
 * в `ProductLinkGuard`. Свой домен проверкой на хост не отличить от чужого
 * (`ChatMarkdown` снимает только ЧУЖИЕ хосты, а выдуманный адрес — свой),
 * поэтому сверяемся не с доменом, а с тем, что реально пришло от
 * инструментов за этот ход: страницы базы знаний, карточки товаров,
 * разделы каталога.
 *
 * Ссылку не вырезаем вместе с текстом: подпись остаётся, исчезает только
 * адрес. «Подробнее — Гарантия и сервис» читается нормально и ничего
 * не обещает, а «Подробнее — » выглядело бы обрывом.
 */
final class OfferedLinkGuard
{
    /**
     * @param  list<string>  $offered  адреса, пришедшие от инструментов в этом ходе
     */
    public function strip(string $text, array $offered): string
    {
        if (trim($text) === '' || ! str_contains($text, '](')) {
            return $text;
        }

        $allowed = [];

        foreach ($offered as $url) {
            $allowed[self::normalize($url)] = true;
        }

        $dropped = [];

        $clean = preg_replace_callback(
            '/\[([^\]\n]*)\]\(\s*([^)\s]+)\s*\)/u',
            static function (array $match) use ($allowed, &$dropped): string {
                $label = $match[1];
                $url = $match[2];

                // Почта и телефон приходят не из инструментов, а из настроек
                // магазина, и подставляет их сам бот — их это правило не касается.
                if (Str::startsWith(Str::lower($url), ['mailto:', 'tel:'])) {
                    return $match[0];
                }

                if (isset($allowed[self::normalize($url)])) {
                    return $match[0];
                }

                $dropped[] = $url;

                // Подпись без адреса. Пустую подпись возвращать нечем —
                // тогда уходит вся конструкция.
                return trim($label) === '' ? '' : $label;
            },
            $text,
        );

        if ($dropped !== []) {
            Log::warning('Assistant invented a link', [
                'urls' => array_slice($dropped, 0, 5),
            ]);
        }

        return $clean ?? $text;
    }

    /**
     * Один и тот же адрес модель пишет то со слешем на конце, то без,
     * то с якорем. Сравниваем по сути: схема и хост в нижнем регистре,
     * путь без хвостового слеша, запрос и якорь отброшены.
     */
    private static function normalize(string $url): string
    {
        $url = trim($url, " \t\n\r\0\x0B<>\"'");
        $parts = parse_url($url);

        if ($parts === false) {
            return Str::lower($url);
        }

        $scheme = Str::lower($parts['scheme'] ?? '');
        $host = Str::lower($parts['host'] ?? '');
        $path = rtrim($parts['path'] ?? '', '/');

        return ($scheme !== '' ? $scheme.'://' : '').$host.$path;
    }
}
