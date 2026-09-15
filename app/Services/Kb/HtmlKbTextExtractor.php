<?php

namespace App\Services\Kb;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * HTML страницы сайта → markdown-подобный текст для базы знаний.
 *
 * У intertooler `pages.content` — HTML-строка из редактора, а не дерево Tiptap,
 * как у kratonshop: TiptapTextExtractor на ней вернул бы пустоту. `strip_tags`
 * тоже не годится — чанкер режет корпус по `##` и `###`, и без разбора
 * разметки заголовков ему не видать, а пункты списка склеятся в одну строку.
 *
 * Поэтому разметка не превращается в текст напрямую, а переводится в то же
 * дерево Tiptap и отдаётся TiptapTextExtractor. Правила вывода — адрес ссылки
 * в скобках, пропуск mailto и tel, подписи картинок, блоки редактора — живут
 * в одном месте и для страниц, и для статей админа: разойтись им не с чего.
 *
 * Уровни заголовков сжимаются до двух: h1–h2 → `##`, h3–h6 → `###`. Заголовок
 * страницы уже лежит в крошках, и h1 внутри содержимого — это раздел, который
 * чанкер должен резать, а не выбрасывать как заголовок документа.
 */
final class HtmlKbTextExtractor
{
    /** Выбрасываются целиком, вместе с содержимым: текста для покупателя в них нет. */
    private const DROPPED = ['script', 'style', 'img', 'svg', 'iframe', 'video', 'audio', 'noscript', 'template'];

    /**
     * Блочные теги. Текст, стоящий между ними без обёртки, собирается в абзац —
     * иначе он склеился бы с соседним блоком в одну строку.
     */
    private const BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    public function __construct(
        private readonly TiptapTextExtractor $tiptap,
    ) {}

    public function toText(?string $html): string
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
            return $this->squash(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $this->tiptap->toText(['type' => 'doc', 'content' => $this->blocks($root)]);
    }

    /**
     * Дети элемента как блоки Tiptap: блочные теги — своими узлами,
     * строчный текст между ними — абзацами.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(DOMNode $parent): array
    {
        $blocks = [];
        $inline = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isBlock($child)) {
                $this->flushParagraph($blocks, $inline);
                array_push($blocks, ...$this->block($child));

                continue;
            }

            array_push($inline, ...$this->inline($child));
        }

        $this->flushParagraph($blocks, $inline);

        return $blocks;
    }

    private function isBlock(DOMElement $element): bool
    {
        return $element->getAttribute('data-type') === 'customBlock'
            || in_array(strtolower($element->tagName), self::BLOCKS, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function block(DOMElement $element): array
    {
        // Блок редактора: содержимое лежит JSON-ом в атрибуте, а не текстом внутри.
        if ($element->getAttribute('data-type') === 'customBlock') {
            return [$this->customBlock($element)];
        }

        return match (strtolower($element->tagName)) {
            'h1', 'h2' => [$this->heading($element, 2)],
            'h3', 'h4', 'h5', 'h6' => [$this->heading($element, 3)],
            'ul' => [$this->list($element, 'bulletList')],
            'ol' => [$this->list($element, 'orderedList')],
            'table' => [$this->table($element)],
            'hr' => [],
            // p, div и прочие обёртки прозрачны: абзацы из их строчного
            // содержимого соберёт blocks().
            default => $this->blocks($element),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function heading(DOMElement $element, int $level): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => $this->inlineChildren($element)];
    }

    /**
     * @return array<string, mixed>
     */
    private function list(DOMElement $element, string $type): array
    {
        $items = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $items[] = ['type' => 'listItem', 'content' => $this->blocks($child)];
            }
        }

        return ['type' => $type, 'content' => $items];
    }

    /**
     * Таблица — одним абзацем, строка таблицы — строкой текста.
     *
     * Две ячейки — «название: значение», больше двух — через « | »: после
     * `strip_tags` конец значения и начало следующего названия ничем не
     * разделены, и модель путает столбцы соседних строк.
     *
     * @return array<string, mixed>
     */
    private function table(DOMElement $table): array
    {
        $content = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $text = $this->squash($cell->textContent);

                    if ($text !== '') {
                        $cells[] = $text;
                    }
                }
            }

            if ($cells === []) {
                continue;
            }

            if ($content !== []) {
                $content[] = ['type' => 'hardBreak'];
            }

            $content[] = [
                'type' => 'text',
                'text' => count($cells) === 2 ? $cells[0].': '.$cells[1] : implode(' | ', $cells),
            ];
        }

        return ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * Блок редактора в узел Tiptap — дальше его разбирает TiptapTextExtractor
     * по тем же правилам, что и в статьях: pdf-link отдаёт ссылку, raw-html —
     * текст, карта и видео — ничего.
     *
     * Картинка — исключение: в HTML-редакторе это блок с подписью в конфиге,
     * а у Tiptap-правила подписи узел свой. Подпись здесь пишет человек
     * в форме блока, поэтому она содержание, а не имя файла.
     *
     * @return array<string, mixed>
     */
    private function customBlock(DOMElement $element): array
    {
        $id = trim($element->getAttribute('data-id'));
        $config = json_decode($element->getAttribute('data-config'), true);
        $config = is_array($config) ? $config : [];

        if ($id === 'image') {
            return ['type' => 'image', 'attrs' => ['alt' => is_string($config['alt'] ?? null) ? $config['alt'] : '']];
        }

        return ['type' => 'customBlock', 'attrs' => ['id' => $id, 'config' => $config]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inlineChildren(DOMNode $parent): array
    {
        $nodes = [];

        foreach ($parent->childNodes as $child) {
            array_push($nodes, ...$this->inline($child));
        }

        return $nodes;
    }

    /**
     * Строчное содержимое: текст, переносы и ссылки. Оформление — strong, em,
     * span с цветом — исчезает, текст втекает в строку.
     *
     * @return list<array<string, mixed>>
     */
    private function inline(DOMNode $node): array
    {
        if ($node instanceof DOMText) {
            $text = $this->collapse($node->textContent);

            return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        }

        if (! $node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROPPED, true)) {
            return [];
        }

        return match ($tag) {
            'br' => [['type' => 'hardBreak']],
            'a' => $this->link($node),
            default => $this->inlineChildren($node),
        };
    }

    /**
     * Ссылка — одним текстовым узлом на весь свой текст, даже если внутри
     * `<strong><u>`: иначе адрес в скобках дописался бы после каждого куска.
     * Пробелы по краям выносятся наружу, чтобы не склеить ссылку с соседним словом.
     *
     * @return list<array<string, mixed>>
     */
    private function link(DOMElement $link): array
    {
        $raw = $this->collapse($link->textContent);
        $text = trim($raw);

        if ($text === '') {
            return $raw === '' ? [] : [['type' => 'text', 'text' => ' ']];
        }

        $node = ['type' => 'text', 'text' => $text];
        $href = trim($link->getAttribute('href'));

        if ($href !== '') {
            $node['marks'] = [['type' => 'link', 'attrs' => ['href' => $href]]];
        }

        return [
            ...(str_starts_with($raw, ' ') ? [['type' => 'text', 'text' => ' ']] : []),
            $node,
            ...(str_ends_with($raw, ' ') ? [['type' => 'text', 'text' => ' ']] : []),
        ];
    }

    /**
     * Накопленный строчный текст — в абзац, если в нём есть хоть одно слово.
     * Переводы строк между тегами в исходном HTML абзацами не считаются.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $inline
     */
    private function flushParagraph(array &$blocks, array &$inline): void
    {
        foreach ($inline as $node) {
            if (($node['type'] ?? null) === 'text' && trim((string) $node['text']) !== '') {
                $blocks[] = ['type' => 'paragraph', 'content' => $inline];

                break;
            }
        }

        $inline = [];
    }

    /** Пробельные символы — как их видит браузер: любая серия, включая неразрывный пробел, это один пробел. */
    private function collapse(string $text): string
    {
        return preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text;
    }

    private function squash(string $text): string
    {
        return trim($this->collapse($text));
    }
}
