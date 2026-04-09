<?php

namespace App\Console\Commands;

use App\Support\Products\ProductCurrencyRateSyncService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class SyncProductCurrencyRates extends Command
{
    protected $signature = 'products:sync-currency-rates';

    protected $description = 'Синхронизирует курсы валют из ЦБ РФ и обновляет товары с автообновлением курса';

    public function handle(ProductCurrencyRateSyncService $service): int
    {
        $this->info('Запуск синхронизации курсов ЦБ РФ для товаров...');

        try {
            $result = $service->sync();
        } catch (Throwable $exception) {
            report($exception);

            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Не удалось синхронизировать курсы товаров.');

            return self::FAILURE;
        }

        $rates = $result['rates'];

        $this->info(sprintf(
            'Курсы обновлены: USD/RUR %s, CNY/RUR %s, EUR/RUR %s (дата %s). Обновлено товаров: %d.',
            $this->formatRate((float) ($rates['USD'] ?? 0)),
            $this->formatRate((float) ($rates['CNY'] ?? 0)),
            $this->formatRate((float) ($rates['EUR'] ?? 0)),
            (string) $result['source_date'],
            (int) $result['updated_products'],
        ));

        return self::SUCCESS;
    }

    private function formatRate(float $value): string
    {
        $formatted = number_format($value, 6, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
