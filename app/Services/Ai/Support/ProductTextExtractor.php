<?php

namespace App\Services\Ai\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Описание товара в текст, пригодный для модели.
 *
 * Существует потому, что `strip_tags` здесь портит главное. Часть описаний
 * несёт характеристики ТАБЛИЦЕЙ — у нас так размечены 228 описаний из 4 001
 * (замер 15.09.2026; у донора таблицы были основной разметкой, `td` и `tr`
 * вдвое частотнее `p`). После `strip_tags` из таблицы получается «Напряжение
 * сети 220 В Объем ресивера 100 л Производительность 425 л/мин»: одна строка,
 * в которой конец значения и начало следующего названия ничем не разделены.
 * Модель на таком путает столбцы соседних строк, а на числах это стоит
 * неверного ответа о товаре. Остальное — списки, переносы, html-сущности
 * и потолок длины — нужно всем 3 605 описаниям каталога.
 *
 * Поэтому таблица разбирается по строкам: две ячейки — «Название: значение»,
 * больше двух — через « | ». Списки становятся пунктами, абзацы — строками.
 *
 * ЧТО ВЫБРАСЫВАЕТСЯ И ПОЧЕМУ. `div[data-type]` — блоки редактора (ссылка
 * на инструкцию, галерея, баннер): внутри у них JSON конфигурации, и в текст
 * для модели он попадать не должен вовсе. Картинки выбрасываются вместе
 * с alt: alt в каталоге — это имя файла. Ссылки остаются подписью, без
 * адреса: адрес товара бот берёт из карточки, а не из текста описания.
 */
final class ProductTextExtractor
{
    /** Блочные теги: каждый начинает новую строку. */
    private const BLOCKS = ['p', 'div', 'tr', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'table', 'ul', 'ol'];

    /** Выбрасываются целиком, вместе с содержимым. */
    private const DROPPED = ['script', 'style', 'img', 'svg', 'iframe', 'video', 'noscript'];

    public function toText(?string $html, int $limit = 2000): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // Обёртка с явной кодировкой обязательна: без неё DOMDocument
        // читает кириллицу как latin1 и портит текст молча.
        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="kb-root">'
                .$html.'</div></body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $document->getElementById('kb-root') : null;

        if ($root === null) {
            // Разметка не разобралась — отдаём хоть что-то, но не мусор.
            return $this->cut($this->squash(strip_tags($html)), $limit);
        }

        $lines = [];
        $buffer = '';

        $this->walk($root, $lines, $buffer);
        $this->flush($lines, $buffer);

        return $this->cut(implode("\n", $lines), $limit);
    }

    /**
     * @param  list<string>  $lines
     */
    private function walk(DOMNode $node, array &$lines, string &$buffer): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $buffer .= $child->textContent;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROPPED, true)) {
                continue;
            }

            // Блок редактора: внутри JSON конфигурации, а не текст товара.
            if ($child->hasAttribute('data-type')) {
                continue;
            }

            if ($tag === 'tr') {
                $this->flush($lines, $buffer);
                $this->pushRow($child, $lines);

                continue;
            }

            if (in_array($tag, self::BLOCKS, true)) {
                $this->flush($lines, $buffer);
                $this->walk($child, $lines, $buffer);
                $this->flush($lines, $buffer);

                continue;
            }

            // Строчные теги — strong, em, a, span, sup: текст втекает
            // в текущую строку, разметка исчезает.
            $this->walk($child, $lines, $buffer);
        }
    }

    /**
     * Строка таблицы. Две ячейки — это «название: значение», и именно так
     * устроены таблицы характеристик в каталоге. Больше двух — редкость
     * (габариты, комплектация), там разделяем вертикальной чертой.
     *
     * @param  list<string>  $lines
     */
    private function pushRow(DOMElement $row, array &$lines): void
    {
        $cells = [];

        foreach ($row->getElementsByTagName('*') as $cell) {
            $tag = strtolower($cell->tagName);

            if ($tag === 'td' || $tag === 'th') {
                $text = $this->squash($cell->textContent);

                if ($text !== '') {
                    $cells[] = $text;
                }
            }
        }

        if ($cells === []) {
            return;
        }

        $lines[] = count($cells) === 2
            ? $cells[0].': '.$cells[1]
            : implode(' | ', $cells);
    }

    /**
     * @param  list<string>  $lines
     */
    private function flush(array &$lines, string &$buffer): void
    {
        $text = $this->squash($buffer);
        $buffer = '';

        if ($text === '') {
            return;
        }

        // Повтор подряд — обычное дело после выброшенных картинок и блоков.
        if (end($lines) === $text) {
            return;
        }

        $lines[] = $text;
    }

    private function squash(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Обрезка по границе слова.
     *
     * Потолок обязателен, а не «на всякий случай»: самое длинное описание
     * в каталоге — 24 439 знаков (замер 03.09.2026), и без обрезки один
     * вопрос о таком товаре стоил бы больше десяти тысяч токенов ввода.
     * Режем с КОНЦА: таблица характеристик стоит в начале, и это самая
     * ценная часть текста.
     */
    private function cut(string $text, int $limit): string
    {
        if ($limit <= 0 || mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \n\t.,;:-").'…';
    }
}
