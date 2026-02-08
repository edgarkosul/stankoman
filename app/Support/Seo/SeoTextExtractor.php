<?php

namespace App\Support\Seo;

use DOMDocument;
use DOMXPath;

class SeoTextExtractor
{
    /**
     * Извлекает человеко-читабельный сниппет из HTML.
     * - вырезает указанные теги (по умолчанию <table>)
     * - берёт первый содержательный <p>
     * - при необходимости добавляет первые пункты списков
     * - мягко обрезает до нужной длины
     */
    public function extractDescriptionFromHtml(
        ?string $html,
        int $softMax = 165,
        int $softMin = 110,
        array $stripTags = ['table']
    ): ?string {
        $html = (string)($html ?? '');
        if ($html === '') {
            return null;
        }
        $clean = $this->removeTags($html, $stripTags);

        $paragraph = $this->firstParagraphText($clean);
        if ($paragraph === null || $paragraph === '') {
            $paragraph = $this->allText($clean);
        }

        if (mb_strlen($this->squash($paragraph)) < 90) {
            $bullets = $this->firstListItems($clean, 3);
            if ($bullets !== '') {
                $paragraph = trim($paragraph . ' ' . $bullets);
            }
        }

        $paragraph = $this->squash($paragraph);
        if ($paragraph === '') {
            return null;
        }

        return $this->truncateSmart($paragraph, $softMax, $softMin);
    }

    /* ======================== DOM helpers ======================== */

    private function dom(string $html): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        // Явно создаём нормальную HTML-структуру и свой корневой контейнер
        $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' .
                '<div id="__root__">' . $html . '</div>' .
                '</body></html>'
        );

        libxml_clear_errors();

        $xp   = new \DOMXPath($dom);
        $root = $xp->query('//*[@id="__root__"]')->item(0);

        return [$dom, $xp, $root];
    }

    private function innerHTML(\DOMNode $node): string
    {
        $out = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $out .= $node->ownerDocument->saveHTML($child);
        }
        return $out;
    }

    private function removeTags(string $html, array $tagNames): string
    {
        [$dom, $xp, $root] = $this->dom($html);

        if (!$root) {
            return $html; // fallback — на всякий
        }

        foreach ($tagNames as $tag) {
            /** @var \DOMNode $node */
            foreach ($xp->query('.//' . $tag, $root) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Возвращаем именно innerHTML нашего контейнера, без <html>/<body>/<meta>
        return $this->innerHTML($root);
    }


    private function firstParagraphText(string $html): ?string
    {
        [$dom, $xp] = $this->dom($html);
        foreach ($xp->query('//p') as $p) {
            $text = $this->squash($p->textContent ?? '');
            // пропускаем технические и слишком короткие
            if ($text !== '' && mb_strlen($text) >= 30) {
                return $text;
            }
        }
        return null;
    }

    private function firstListItems(string $html, int $limit = 3): string
    {
        [, $xp] = $this->dom($html);
        $items = [];
        foreach ($xp->query('//ul/li|//ol/li') as $li) {
            $t = $this->squash($li->textContent ?? '');
            if ($t !== '') {
                $items[] = $t;
            }
            if (count($items) >= $limit) break;
        }
        return $items ? implode('; ', $items) . '.' : '';
    }

    private function allText(string $html): string
    {
        [$dom,] = $this->dom($html);
        return $this->squash($dom->textContent ?? '');
    }

    private function squash(string $text): string
    {
        // NBSP варианты
        $text = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim($text);
    }

    private function truncateSmart(string $text, int $softMax, int $softMin): string
    {
        $len = mb_strlen($text);
        if ($len <= $softMax) return $text;

        $window = mb_substr($text, 0, $softMax + 1);

        // конец предложения предпочтительнее
        $punctPos = max(
            mb_strrpos($window, '.'),
            mb_strrpos($window, '!'),
            mb_strrpos($window, '?')
        );
        if ($punctPos !== false && $punctPos >= $softMin) {
            return rtrim(mb_substr($window, 0, $punctPos + 1));
        }

        // иначе — по последнему пробелу
        $spacePos = mb_strrpos($window, ' ');
        if ($spacePos !== false && $spacePos >= $softMin) {
            return rtrim(mb_substr($window, 0, $spacePos)) . '…';
        }

        // крайний случай
        return rtrim(mb_substr($text, 0, $softMax)) . '…';
    }
}
