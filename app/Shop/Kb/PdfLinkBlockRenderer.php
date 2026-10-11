<?php

namespace App\Shop\Kb;

use App\Support\Filament\PdfLinkBlockConfigNormalizer;
use Illuminate\Support\Facades\Storage;
use Siteko\Chatbot\Kb\Contracts\KbBlockRenderer;

/**
 * Блок «Ссылка на документ». Адрес собираем той же логикой, что и фронт,
 * иначе бот назовёт файл, которого по этому адресу нет.
 *
 * Перенесено из движка intertooler как есть — см. ShipmentBlockRenderer.
 */
final class PdfLinkBlockRenderer implements KbBlockRenderer
{
    public function id(): string
    {
        return 'pdf-link';
    }

    public function render(array $config): array
    {
        $text = trim((string) ($config['link_text'] ?? ''));
        $href = $this->href($config);

        if ($text === '' && $href === null) {
            return [];
        }

        $text = $text !== '' ? $text : 'Документ';

        return [$href !== null ? $text.' ('.$href.')' : $text];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function href(array $config): ?string
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
}
