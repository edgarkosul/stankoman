<?php

namespace App\Services\Kb\Data;

/** Найденный фрагмент базы знаний вместе с его близостью к вопросу. */
final readonly class KbHit
{
    /**
     * @param  list<string>  $breadcrumb
     * @param  list<string>  $sectionPath
     */
    public function __construct(
        public string $chunkId,
        public string $source,
        public ?string $url,
        public string $title,
        public array $breadcrumb,
        public array $sectionPath,
        public string $text,
        public float $score,
    ) {}

    /** «InterTooler.ru › Доставка и оплата › ч. 1» — для показа админу. */
    public function path(): string
    {
        return implode(' › ', [...$this->breadcrumb, ...$this->sectionPath]);
    }
}
