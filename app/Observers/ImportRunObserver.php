<?php

namespace App\Observers;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

class ImportRunObserver
{
    public function updated(ImportRun $importRun): void
    {
        if (! $importRun->wasChanged('status')) {
            return;
        }

        if (! in_array((string) $importRun->status, ['dry_run', 'applied', 'completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $recipient = $importRun->user;

        if (! $recipient) {
            return;
        }

        $notification = Notification::make()
            ->title($this->titleFor($importRun))
            ->body($this->bodyFor($importRun))
            ->actions([
                Action::make('open_import_history')
                    ->label('История импортов')
                    ->markAsRead()
                    ->url(ImportRunResource::getUrl()),
            ]);

        match ((string) $importRun->status) {
            'applied' => $notification->success(),
            'completed' => $notification->success(),
            'dry_run' => $notification->info(),
            'cancelled' => $notification->warning(),
            default => $notification->danger(),
        };

        $recipient->notifyNow($notification->toDatabase());
    }

    private function titleFor(ImportRun $importRun): string
    {
        return match ((string) $importRun->status) {
            'dry_run' => "Dry-run #{$importRun->id} завершен",
            'applied' => "Импорт #{$importRun->id} завершен",
            'completed' => "Импорт #{$importRun->id} завершен",
            'failed' => "Импорт #{$importRun->id} завершился ошибкой",
            'cancelled' => "Импорт #{$importRun->id} остановлен",
            default => "Импорт #{$importRun->id} обновлен",
        };
    }

    private function bodyFor(ImportRun $importRun): string
    {
        $totals = $this->totalsForNotification($importRun);

        if ($importRun->status === 'failed') {
            return sprintf(
                'Тип: %s. Ошибок: %d. Проверьте запуск #%d в истории импортов.',
                $this->typeLabel((string) $importRun->type),
                $totals['error'],
                $importRun->id,
            );
        }

        if ($importRun->status === 'cancelled') {
            return sprintf(
                'Тип: %s. Запуск остановлен вручную. Обработано: %d. Ошибок: %d.',
                $this->typeLabel((string) $importRun->type),
                (int) data_get($importRun->totals, 'scanned', 0),
                $totals['error'],
            );
        }

        if (in_array((string) $importRun->status, ['applied', 'completed'], true)) {
            return sprintf(
                'Тип: %s. Создано: %d, обновлено: %d, без изменений: %d, конфликтов: %d, ошибок: %d.',
                $this->typeLabel((string) $importRun->type),
                $totals['create'],
                $totals['update'],
                $totals['same'],
                $totals['conflict'],
                $totals['error'],
            );
        }

        return sprintf(
            'Тип: %s. Создастся: %d, обновится: %d, без изменений: %d, конфликтов: %d, ошибок: %d.',
            $this->typeLabel((string) $importRun->type),
            $totals['create'],
            $totals['update'],
            $totals['same'],
            $totals['conflict'],
            $totals['error'],
        );
    }

    /**
     * @return array{create:int,update:int,same:int,conflict:int,error:int}
     */
    private function totalsForNotification(ImportRun $importRun): array
    {
        $totals = $importRun->totals;

        if (! is_array($totals)) {
            $totals = [];
        }

        $applied = Arr::get($totals, 'applied');

        if (! is_array($applied)) {
            $applied = [];
        }

        return [
            'create' => (int) ($totals['create'] ?? $applied['created'] ?? 0),
            'update' => (int) ($totals['update'] ?? $applied['updated'] ?? 0),
            'same' => (int) ($totals['same'] ?? $applied['same'] ?? 0),
            'conflict' => (int) ($totals['conflict'] ?? $applied['conflict'] ?? 0),
            'error' => (int) ($totals['error'] ?? $applied['error'] ?? 0),
        ];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'products' => 'Excel товары',
            'category_filters' => 'Категорийные фильтры',
            'vactool_products' => 'Vactool',
            'metalmaster_products' => 'Metalmaster',
            'yandex_market_feed_products' => 'Yandex Market Feed',
            'specs_match' => 'Specs match',
            default => $type !== '' ? $type : 'unknown',
        };
    }
}
