<?php

namespace App\Support\CatalogImport\Enums;

enum ImportRunStatus: string
{
    case Pending = 'pending';
    case DryRun = 'dry_run';
    case Applied = 'applied';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::DryRun,
            self::Applied,
            self::Completed,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this, [
            self::DryRun,
            self::Applied,
            self::Completed,
        ], true);
    }
}
