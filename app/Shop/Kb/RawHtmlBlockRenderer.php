<?php

namespace App\Shop\Kb;

use Siteko\Chatbot\Kb\Contracts\KbBlockRenderer;

/**
 * Блок «Произвольный HTML» внутри страницы. Здесь `strip_tags` уместен —
 * в отличие от корневого документа, тут действительно html.
 *
 * Перенесено из движка intertooler как есть — см. ShipmentBlockRenderer.
 */
final class RawHtmlBlockRenderer implements KbBlockRenderer
{
    public function id(): string
    {
        return 'raw-html';
    }

    public function render(array $config): array
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
