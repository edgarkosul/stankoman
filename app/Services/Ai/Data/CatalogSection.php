<?php

namespace App\Services\Ai\Data;

/**
 * Листовой раздел каталога — у него есть своя страница, и его можно дать ссылкой.
 */
final readonly class CatalogSection
{
    public function __construct(
        public int $id,
        /** Путь от корня: «Компрессоры › Винтовые компрессоры». */
        public string $path,
        public string $url,
        /** Сколько АКТИВНЫХ товаров в разделе. */
        public int $productsCount,
    ) {}
}
