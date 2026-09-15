<?php

namespace App\Services\Kb;

use App\Support\Filament\PdfLinkBlockConfigNormalizer;
use Illuminate\Support\Facades\Storage;

/**
 * Tiptap-документ → markdown-подобный текст для базы знаний.
 *
 * Статьи базы знаний хранятся JSON-деревом Tiptap (`kb_articles.content`).
 * Страницы сайта у intertooler хранят HTML — их HtmlKbTextExtractor переводит
 * в это же дерево и отдаёт сюда, так что правила вывода ниже общие для обоих.
 *
 * На выходе намеренно markdown, а не голый текст: чанкер режет корпус по
 * заголовкам `##`, и восстанавливать иерархию разделов больше не из чего.
 *
 * Ссылки дописываются в скобках после текста. Без этого бот знает, что
 * «подробности на странице оплаты», но дать адрес не может — а половина
 * полезных ответов магазина как раз «вот ссылка, там форма».
 *
 * Незнакомый узел не роняет разбор: спускаемся в его `content` и продолжаем —
 * новый блок в редакторе не должен ломать индексацию.
 */
final class TiptapTextExtractor
{
    /** Кастомные блоки без текстовой ценности: иллюстрации и виджеты. */
    private const SKIPPED_BLOCKS = ['hero-slider', 'image_gallery', 'delivery_map'];

    /**
     * @param  array<mixed>|string|null  $document
     */
    public function toText(array|string|null $document): string
    {
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            $document = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($document)) {
            return '';
        }

        $blocks = $this->renderBlocks($document['content'] ?? [$document]);

        // Схлопываем пустые блоки и лишние переводы строк: чанкер делит текст
        // по двойному переносу, и «дыры» из-под картинок иначе порождают
        // фантомные абзацы.
        $text = implode("\n\n", array_filter(array_map('trim', $blocks), static fn (string $b): bool => $b !== ''));

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<string>
     */
    private function renderBlocks(array $nodes): array
    {
        $blocks = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach ($this->renderBlock($node) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Подпись картинки: alt, а при его отсутствии — title.
     *
     * Оба атрибута пишет человек и оба предназначены читателю, поэтому
     * в текст они идут как есть, без пометок вроде «[изображение]»:
     * подпись — это содержание, а не служебная отметка.
     *
     * @param  array<string, mixed>  $node
     */
    private function imageCaption(array $node): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        foreach (['alt', 'title'] as $key) {
            $caption = trim((string) ($attrs[$key] ?? ''));

            if ($caption !== '') {
                return $caption;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function renderBlock(array $node): array
    {
        $type = (string) ($node['type'] ?? '');
        $content = $node['content'] ?? [];

        return match ($type) {
            // Прозрачные контейнеры вёрстки: колонки — это раскладка, а не
            // смысл, и в тексте они не должны оставлять следов.
            'doc', 'grid', 'gridColumn' => $this->renderBlocks($content),

            'heading' => [$this->renderHeading($node)],
            'paragraph' => [$this->renderInline($content)],
            'bulletList' => [$this->renderList($content, ordered: false)],
            'orderedList' => [$this->renderList($content, ordered: true)],
            'blockquote' => [$this->renderInline($content)],
            'codeBlock' => [$this->renderInline($content)],

            // Разделитель текста не несёт.
            'horizontalRule' => [],

            /*
             * Картинка отдаёт свою подпись, если она есть.
             *
             * У донора здесь стояло безусловное [] с комментарием «alt почти
             * везде null». Комментарий был верен, но вывод из него неправильный.
             * Дороже всего обошёлся как раз этот случай: на странице расчёта
             * доставки транспортные компании были показаны ОДНИМИ логотипами,
             * и бот, не найдя названий в тексте, вывел их из доменов в адресах
             * ссылок. Угадал верно, но рассуждать о магазине ему запрещено.
             *
             * Пустой alt по-прежнему не даёт ничего, так что мусора в индексе
             * не прибавится ни на байт. Зато подпись, которую кто-то однажды
             * напишет, дойдёт до бота сама, без правки кода.
             */
            'image' => array_values(array_filter([$this->imageCaption($node)])),

            'customBlock' => $this->renderCustomBlock($node),

            // Незнакомый узел: не игнорируем целиком, а спускаемся внутрь.
            default => $content !== [] ? $this->renderBlocks($content) : [],
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderHeading(array $node): string
    {
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 2)));
        $text = $this->renderInline($node['content'] ?? []);

        return $text === '' ? '' : str_repeat('#', $level).' '.$text;
    }

    /**
     * @param  array<mixed>  $items
     */
    private function renderList(array $items, bool $ordered): string
    {
        $lines = [];
        $number = 1;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            // listItem внутри содержит абзацы; вложенные списки схлопываем
            // в те же строки — иерархия пунктов для поиска роли не играет.
            $text = trim(implode(' ', array_map('trim', $this->renderBlocks($item['content'] ?? []))));

            if ($text === '') {
                continue;
            }

            $lines[] = ($ordered ? $number.'. ' : '- ').$text;
            $number++;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<mixed>  $nodes
     */
    private function renderInline(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $out .= match ((string) ($node['type'] ?? '')) {
                'text' => $this->renderText($node),
                'hardBreak' => "\n",
                // Картинка внутри абзаца — та же подпись, но в строку.
                'image' => $this->imageCaption($node),
                default => $this->renderInline($node['content'] ?? []),
            };
        }

        // Пробелы вокруг переносов внутри абзаца схлопываем, сами переносы бережём.
        $out = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $out) ?? $out;

        return trim(preg_replace('/[ \t]+/u', ' ', $out) ?? $out);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderText(array $node): string
    {
        $text = (string) ($node['text'] ?? '');

        // Жирный, подчёркивание и цвет — оформление; для эмбеддинга это шум.
        // Ссылка — единственная марка, несущая факт, которого нет в тексте.
        foreach ($node['marks'] ?? [] as $mark) {
            if (! is_array($mark) || ($mark['type'] ?? null) !== 'link') {
                continue;
            }

            $href = trim((string) ($mark['attrs']['href'] ?? ''));

            if ($this->shouldAppendHref($href, $text)) {
                return $text.' ('.$href.')';
            }
        }

        return $text;
    }

    /**
     * Дописывать ли адрес в скобках после текста ссылки.
     *
     * На странице контактов это решает вполне земную проблему: там почта
     * размечена ссылкой на саму себя, и наивная подстановка давала
     * «адрес (mailto:адрес)» по три раза подряд. Для эмбеддинга такой
     * повтор — чистый шум, вытесняющий полезные слова.
     */
    private function shouldAppendHref(string $href, string $text): bool
    {
        // Пустой текст: скобка без подписи не значит ничего.
        if ($href === '' || trim($text) === '') {
            return false;
        }

        // Якорь внутри страницы адресом не является.
        if (str_starts_with($href, '#')) {
            return false;
        }

        // mailto: и tel: — техническая обёртка вокруг того же, что уже в тексте.
        if (preg_match('#^(mailto|tel):#i', $href) === 1) {
            return false;
        }

        // Текст ссылки и есть адрес — дублировать незачем. Сравниваем
        // без схемы и хвостового слэша: «intertooler.ru/page/kontakty» и
        // «https://intertooler.ru/page/kontakty/» это один и тот же адрес.
        return $this->canonicalUrl($href) !== $this->canonicalUrl($text);
    }

    private function canonicalUrl(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('#^[a-z]+://#', '', $value) ?? $value;

        return rtrim($value, '/');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function renderCustomBlock(array $node): array
    {
        $id = (string) ($node['attrs']['id'] ?? '');
        $config = $node['attrs']['config'] ?? [];

        if (! is_array($config) || in_array($id, self::SKIPPED_BLOCKS, true)) {
            return [];
        }

        return match ($id) {
            'shipment' => $this->renderShipment($config),
            'pdf-link' => $this->renderPdfLink($config),
            'raw-html' => $this->renderRawHtml($config),
            default => [],
        };
    }

    /**
     * Отчёт об отгрузке: заголовок и ссылка на отгруженный товар.
     * Фотографии и rutube пропускаем — текста в них нет.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function renderShipment(array $config): array
    {
        $parts = [];

        $heading = trim((string) ($config['heading'] ?? ''));

        if ($heading !== '') {
            $parts[] = $heading;
        }

        $label = trim((string) ($config['link_label'] ?? ''));
        $url = trim((string) ($config['link_url'] ?? ''));

        if ($label !== '') {
            $parts[] = $url !== '' ? $label.' ('.$url.')' : $label;
        }

        return $parts === [] ? [] : [implode("\n", $parts)];
    }

    /**
     * Ссылка на документ. Адрес собираем той же логикой, что и фронт, иначе
     * бот назовёт файл, которого по этому адресу нет.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function renderPdfLink(array $config): array
    {
        $text = trim((string) ($config['link_text'] ?? ''));
        $href = $this->resolveDocumentHref($config);

        if ($text === '' && $href === null) {
            return [];
        }

        $text = $text !== '' ? $text : 'Документ';

        return [$href !== null ? $text.' ('.$href.')' : $text];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveDocumentHref(array $config): ?string
    {
        $sourceType = trim((string) ($config['source_type'] ?? ''));
        $file = trim((string) ($config['file'] ?? ''));
        $url = trim((string) ($config['url'] ?? ''));

        if ($sourceType !== PdfLinkBlockConfigNormalizer::SOURCE_DIRECT_URL && $file !== '') {
            $disk = Storage::disk(PdfLinkBlockConfigNormalizer::DISK);

            // Файл мог быть удалён из хранилища, а блок остаться. Ссылку на
            // несуществующий документ давать хуже, чем не давать никакой.
            if ($disk->exists($file)) {
                return $disk->url($file);
            }
        }

        return $url !== '' ? $url : null;
    }

    /**
     * Произвольный html внутри страницы. Здесь `strip_tags` уместен — в отличие
     * от корневого документа, тут действительно html.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function renderRawHtml(array $config): array
    {
        $html = (string) ($config['html'] ?? '');

        if (trim($html) === '') {
            return [];
        }

        // <br> и </p> обязаны стать переносами до вырезания тегов, иначе
        // соседние строки склеятся в одно слово.
        $html = preg_replace('#<\s*(br|/p|/div|/li|/tr)\s*/?\s*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = trim(preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text);

        return $text === '' ? [] : [$text];
    }
}
