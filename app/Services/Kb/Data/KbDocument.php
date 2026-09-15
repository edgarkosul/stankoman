<?php

namespace App\Services\Kb\Data;

/**
 * Один документ базы знаний, уже приведённый к тексту.
 *
 * Граница между «откуда взялся контент» и «как он индексируется». Всё, что
 * ниже по течению — чанкер, эмбеддинги, хранилище — про модели проекта не
 * знает ничего и работать с ними не должно.
 */
final readonly class KbDocument
{
    /**
     * @param  string|null  $url  публичный адрес; null для документов без страницы на сайте
     * @param  list<string>  $breadcrumb  путь, который уйдёт в эмбеддинг префиксом
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $breadcrumb,
        public string $text,
        public ?string $url = null,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
