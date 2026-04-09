<?php

namespace App\Support\Products;

use App\Enums\ProductWholesaleCurrency;
use App\Enums\SettingType;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class ProductCurrencyRateSyncService
{
    private const SOURCE_URL = 'https://www.cbr.ru/scripts/XML_daily.asp';

    /**
     * @return array{source_date: string, rates: array<string, float>, updated_products: int}
     */
    public function sync(): array
    {
        $ratesPayload = $this->fetchCurrentRates();

        DB::transaction(function () use ($ratesPayload): void {
            if (! Schema::hasTable('settings')) {
                return;
            }

            foreach ($ratesPayload['rates'] as $currency => $rate) {
                $this->upsertRate($currency, $rate);
            }

            $this->upsertSourceDate($ratesPayload['source_date']);
        });

        foreach ($ratesPayload['rates'] as $currency => $rate) {
            config()->set($this->settingsConfigKey($currency), $rate);
        }

        config()->set('settings.product_currency.source_date', $ratesPayload['source_date']);

        $updatedProducts = $this->syncProductsUsingRates($ratesPayload['rates']);

        return [
            'source_date' => $ratesPayload['source_date'],
            'rates' => $ratesPayload['rates'],
            'updated_products' => $updatedProducts,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function currentRates(bool $refresh = false): array
    {
        if ($refresh) {
            return $this->sync()['rates'];
        }

        $rates = [];

        foreach (ProductWholesaleCurrency::cases() as $currency) {
            $configuredRate = config($this->settingsConfigKey($currency->value));

            if (is_numeric($configuredRate)) {
                $rates[$currency->value] = (float) $configuredRate;
            }
        }

        if (count($rates) === count(ProductWholesaleCurrency::cases())) {
            return $rates;
        }

        return $this->sync()['rates'];
    }

    public function resolveRateForCurrency(mixed $currency, bool $refresh = false): ?float
    {
        $currencyCase = ProductWholesaleCurrency::fromInput($currency);

        if (! $currencyCase instanceof ProductWholesaleCurrency) {
            return null;
        }

        if ($currencyCase === ProductWholesaleCurrency::Rur) {
            return 1.0;
        }

        $rates = $this->currentRates($refresh);

        return $rates[$currencyCase->value] ?? null;
    }

    /**
     * @param  array<string, float>|null  $rates
     */
    public function syncProductsUsingRates(?array $rates = null): int
    {
        $rates ??= $this->currentRates();
        $updatedProducts = 0;

        Product::query()
            ->where('auto_update_exchange_rate', true)
            ->select([
                'id',
                'wholesale_currency',
                'wholesale_price',
                'exchange_rate',
                'wholesale_price_rub',
                'markup_multiplier',
                'price_amount',
            ])
            ->chunkById(200, function (EloquentCollection $chunk) use ($rates, &$updatedProducts): void {
                /** @var Product $product */
                foreach ($chunk as $product) {
                    $currency = ProductWholesaleCurrency::fromInput($product->wholesale_currency);

                    if (! $currency instanceof ProductWholesaleCurrency) {
                        continue;
                    }

                    $exchangeRate = $currency === ProductWholesaleCurrency::Rur
                        ? 1.0
                        : ($rates[$currency->value] ?? null);

                    if ($exchangeRate === null) {
                        continue;
                    }

                    $payload = $this->buildPricingPayload(
                        product: $product,
                        exchangeRate: $exchangeRate,
                    );

                    $product->update($payload);
                    $updatedProducts++;
                }
            });

        return $updatedProducts;
    }

    /**
     * @return array{source_date: string, rates: array<string, float>}
     */
    protected function fetchCurrentRates(): array
    {
        try {
            $response = Http::timeout(15)->get(self::SOURCE_URL);
        } catch (Throwable $exception) {
            throw new RuntimeException('Не удалось получить курсы ЦБ РФ.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('ЦБ РФ вернул ошибку при загрузке курсов.');
        }

        return $this->parseResponse((string) $response->body());
    }

    /**
     * @return array{source_date: string, rates: array<string, float>}
     */
    protected function parseResponse(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('ЦБ РФ вернул некорректный XML.');
        }

        $rates = [
            ProductWholesaleCurrency::Usd->value => $this->extractRate($xml, 'USD'),
            ProductWholesaleCurrency::Cny->value => $this->extractRate($xml, 'CNY'),
            ProductWholesaleCurrency::Eur->value => $this->extractRate($xml, 'EUR'),
            ProductWholesaleCurrency::Rur->value => 1.0,
        ];

        $sourceDate = trim((string) $xml['Date']);

        return [
            'source_date' => $sourceDate !== '' ? $sourceDate : now()->format('d.m.Y'),
            'rates' => $rates,
        ];
    }

    protected function extractRate(SimpleXMLElement $xml, string $charCode): float
    {
        $nodes = $xml->xpath(sprintf('/ValCurs/Valute[CharCode="%s"]', $charCode));

        if (! is_array($nodes) || ! isset($nodes[0]) || ! $nodes[0] instanceof SimpleXMLElement) {
            throw new RuntimeException("Не найден курс {$charCode} в ответе ЦБ РФ.");
        }

        $valute = $nodes[0];
        $nominal = $this->parseDecimal((string) $valute->Nominal);
        $value = $this->parseDecimal((string) $valute->Value);

        if ($nominal <= 0.0) {
            throw new RuntimeException("Некорректный номинал для {$charCode}.");
        }

        return $value / $nominal;
    }

    protected function parseDecimal(string $value): float
    {
        return (float) str_replace(',', '.', trim($value));
    }

    protected function upsertRate(string $currency, float $rate): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $this->settingsKey($currency)],
            [
                'value' => $this->formatRateForStorage($rate),
                'type' => SettingType::Float,
                'autoload' => true,
            ],
        );
    }

    protected function upsertSourceDate(string $sourceDate): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'product_currency.source_date'],
            [
                'value' => $sourceDate,
                'type' => SettingType::String,
                'autoload' => true,
            ],
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    public function buildPricingPayload(Product $product, float $exchangeRate): array
    {
        $payload = [
            'exchange_rate' => $this->formatDecimalForStorage($exchangeRate, 2),
        ];

        $wholesalePriceRub = Product::calculateWholesalePriceRub(
            $product->wholesale_price,
            $exchangeRate,
        );

        if ($wholesalePriceRub !== null) {
            $payload['wholesale_price_rub'] = $this->formatDecimalForStorage($wholesalePriceRub, 0);
        }

        $sitePriceAmount = Product::calculateSitePriceAmount(
            $wholesalePriceRub ?? $product->wholesale_price_rub,
            $product->markup_multiplier,
        );

        if ($sitePriceAmount !== null) {
            $payload['price_amount'] = $sitePriceAmount;
        }

        $marginAmountRub = Product::calculateMarginAmountRub(
            $sitePriceAmount ?? $product->price_amount,
            $wholesalePriceRub ?? $product->wholesale_price_rub,
        );

        if ($marginAmountRub !== null) {
            $payload['margin_amount_rub'] = $this->formatDecimalForStorage($marginAmountRub, 2);
        }

        return $payload;
    }

    protected function formatRateForStorage(float $rate): string
    {
        return $this->formatDecimalForStorage($rate, 2);
    }

    protected function formatDecimalForStorage(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }

    protected function settingsKey(string $currency): string
    {
        return 'product_currency.'.strtolower($currency).'_to_rub';
    }

    protected function settingsConfigKey(string $currency): string
    {
        return 'settings.'.$this->settingsKey($currency);
    }
}
