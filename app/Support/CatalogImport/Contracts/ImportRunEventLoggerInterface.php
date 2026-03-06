<?php

namespace App\Support\CatalogImport\Contracts;

use App\Support\CatalogImport\Runs\ImportRunEventData;

interface ImportRunEventLoggerInterface
{
    public function log(ImportRunEventData $event): void;

    /**
     * @param  iterable<int, ImportRunEventData>  $events
     */
    public function logMany(iterable $events): void;

    public function flush(): void;
}
