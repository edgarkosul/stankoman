<?php

namespace App\Support\CatalogImport\Runs;

use App\Models\ImportRunEvent;
use App\Support\CatalogImport\Contracts\ImportRunEventLoggerInterface;

final class DatabaseImportRunEventLogger implements ImportRunEventLoggerInterface
{
    private const DEFAULT_BATCH_SIZE = 250;

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    public function __construct(
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {}

    public function log(ImportRunEventData $event): void
    {
        $this->buffer[] = $event->toDatabaseRow();

        if (count($this->buffer) >= max(1, $this->batchSize)) {
            $this->flush();
        }
    }

    /**
     * @param  iterable<int, ImportRunEventData>  $events
     */
    public function logMany(iterable $events): void
    {
        foreach ($events as $event) {
            $this->log($event);
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        ImportRunEvent::query()->insert($this->buffer);
        $this->buffer = [];
    }
}
