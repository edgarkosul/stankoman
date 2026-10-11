<?php

namespace App\Shop\Kb;

use Siteko\Chatbot\Kb\Contracts\KbBlockRenderer;

/**
 * Блок «Отчёт об отгрузке»: заголовок и ссылка на отгруженный товар.
 * Фотографии и rutube пропускаем — текста в них нет.
 *
 * Перенесено из движка intertooler как есть: текст блока уходит в вектор
 * фрагмента, и любое расхождение пересчитало бы векторы всех страниц с ним.
 */
final class ShipmentBlockRenderer implements KbBlockRenderer
{
    public function id(): string
    {
        return 'shipment';
    }

    public function render(array $config): array
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
}
