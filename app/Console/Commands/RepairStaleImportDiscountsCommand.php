<?php

namespace App\Console\Commands;

use App\Models\ImportRun;
use App\Models\ImportRunEvent;
use App\Models\Product;
use App\Support\Products\ProductSearchSync;
use Illuminate\Console\Command;

/**
 * Чинит скидки, отставшие от цены после прогона импорта.
 *
 * До появления пропорционального пересчёта (ProductImportProcessor) импорт мог
 * поднять цену и оставить скидочную цену от старой цены — товар начинал
 * продаваться с большей скидкой, чем задумано. Прежний процент восстанавливаем
 * по журналу прогона: в import_run_events лежит цена до и после обновления.
 */
class RepairStaleImportDiscountsCommand extends Command
{
    protected $signature = 'catalog:repair-stale-import-discounts
        {run : ID прогона импорта, после которого скидки отстали}
        {--show-samples=20 : Сколько строк плана напечатать}
        {--write : Записать новые скидочные цены в базу}';

    protected $description = 'Восстановить процент скидки у товаров, которым импорт поднял цену, но оставил старую скидочную цену.';

    public function handle(ProductSearchSync $searchSync): int
    {
        $runId = (int) $this->argument('run');
        $run = ImportRun::query()->find($runId);

        if (! $run instanceof ImportRun) {
            $this->error('Прогон импорта #'.$runId.' не найден.');

            return self::INVALID;
        }

        $write = (bool) $this->option('write');
        $showSamples = max(0, (int) $this->option('show-samples'));

        $this->line('Прогон: #'.$run->id.' ('.$run->type.', '.($run->finished_at?->format('Y-m-d H:i') ?? 'не завершён').')');
        $this->line('Режим: '.($write ? 'write' : 'dry-run'));

        $plan = $this->buildPlan($runId);

        if ($plan['rows'] === []) {
            $this->warn('Отставших скидок по этому прогону не найдено.');
            $this->renderSummary($plan, 0);

            return self::SUCCESS;
        }

        if ($showSamples > 0) {
            $this->newLine();
            $this->table(
                ['product_id', 'sku', 'цена', 'скидка сейчас', 'скидка станет', '% сейчас', '% станет'],
                array_map(
                    static fn (array $row): array => [
                        (string) $row['product_id'],
                        (string) $row['sku'],
                        (string) $row['price'],
                        (string) $row['discount_now'],
                        (string) $row['discount_next'],
                        $row['percent_now'].'%',
                        $row['percent_next'].'%',
                    ],
                    array_slice($plan['rows'], 0, $showSamples),
                ),
            );

            if (count($plan['rows']) > $showSamples) {
                $this->line('Показаны первые '.$showSamples.' из '.count($plan['rows']).'.');
            }
        }

        $updated = 0;

        if ($write) {
            $updatedIds = [];

            foreach ($plan['rows'] as $row) {
                $product = Product::query()->find($row['product_id']);

                if (! $product instanceof Product) {
                    continue;
                }

                // Между планом и записью товар мог уже кто-то поправить.
                if ((int) $product->price_amount !== $row['price']
                    || (int) $product->discount_price !== $row['discount_now']) {
                    continue;
                }

                $product->discount_price = $row['discount_next'];
                $product->save();

                $updatedIds[] = $product->id;
            }

            $updated = count($updatedIds);

            if ($updatedIds !== []) {
                $searchSync->syncIds($updatedIds);
            }
        }

        $this->renderSummary($plan, $updated);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     rows: array<int, array<string, int|string>>,
     *     price_changes: int,
     *     skipped_percent: int,
     *     skipped_no_discount: int,
     *     skipped_price_moved: int,
     *     skipped_discount_touched: int,
     *     missing_products: int
     * }
     */
    private function buildPlan(int $runId): array
    {
        $rows = [];
        $priceChanges = 0;
        $skippedPercent = 0;
        $skippedNoDiscount = 0;
        $skippedPriceMoved = 0;
        $skippedDiscountTouched = 0;
        $missingProducts = 0;

        ImportRunEvent::query()
            ->where('run_id', $runId)
            ->where('result', 'updated')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->chunkById(500, function ($events) use (
                &$rows,
                &$priceChanges,
                &$skippedPercent,
                &$skippedNoDiscount,
                &$skippedPriceMoved,
                &$skippedDiscountTouched,
                &$missingProducts,
            ): void {
                $changesByProduct = [];

                foreach ($events as $event) {
                    $changes = is_array($event->context['changes'] ?? null) ? $event->context['changes'] : [];
                    $priceChange = $changes['price_amount'] ?? null;

                    if (! is_array($priceChange)) {
                        continue;
                    }

                    $before = (int) ($priceChange['before'] ?? 0);
                    $after = (int) ($priceChange['after'] ?? 0);

                    if ($before <= 0 || $after <= 0 || $before === $after) {
                        continue;
                    }

                    $priceChanges++;

                    if (array_key_exists('discount_price', $changes)) {
                        // Скидку этот прогон уже трогал — чинить нечего.
                        $skippedDiscountTouched++;

                        continue;
                    }

                    // На один товар может быть несколько событий: берём первую цену «до».
                    $productId = (int) $event->product_id;
                    $changesByProduct[$productId] ??= ['before' => $before, 'after' => $after];
                    $changesByProduct[$productId]['after'] = $after;
                }

                if ($changesByProduct === []) {
                    return;
                }

                $products = Product::query()
                    ->whereIn('id', array_keys($changesByProduct))
                    ->get(['id', 'sku', 'price_amount', 'discount_price', 'discount_percent'])
                    ->keyBy('id');

                foreach ($changesByProduct as $productId => $prices) {
                    $product = $products->get($productId);

                    if (! $product instanceof Product) {
                        $missingProducts++;

                        continue;
                    }

                    if ($product->discount_percent !== null) {
                        // Процентную скидку модель пересчитала сама.
                        $skippedPercent++;

                        continue;
                    }

                    $price = (int) $product->price_amount;
                    $discount = (int) ($product->discount_price ?? 0);

                    if ($discount <= 0 || $discount >= $price) {
                        $skippedNoDiscount++;

                        continue;
                    }

                    if ($price !== $prices['after']) {
                        // Цена изменилась уже после прогона — исходный процент неизвестен.
                        $skippedPriceMoved++;

                        continue;
                    }

                    $next = max(1, (int) round($discount * $prices['after'] / $prices['before']));

                    if ($next === $discount) {
                        continue;
                    }

                    $rows[] = [
                        'product_id' => $productId,
                        'sku' => (string) ($product->sku ?? ''),
                        'price' => $price,
                        'discount_now' => $discount,
                        'discount_next' => $next,
                        'percent_now' => $this->percent($price, $discount),
                        'percent_next' => $this->percent($price, $next),
                    ];
                }
            });

        return [
            'rows' => $rows,
            'price_changes' => $priceChanges,
            'skipped_percent' => $skippedPercent,
            'skipped_no_discount' => $skippedNoDiscount,
            'skipped_price_moved' => $skippedPriceMoved,
            'skipped_discount_touched' => $skippedDiscountTouched,
            'missing_products' => $missingProducts,
        ];
    }

    private function percent(int $price, int $discount): int
    {
        if ($price <= 0 || $discount <= 0) {
            return 0;
        }

        return (int) round(100 - ($discount / $price) * 100);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function renderSummary(array $plan, int $updated): void
    {
        $this->newLine();
        $this->info('Итого:');
        $this->line('  товаров с изменённой ценой в прогоне: '.$plan['price_changes']);
        $this->line('  к исправлению: '.count($plan['rows']));
        $this->line('  обновлено: '.$updated);
        $this->line('  пропущено (скидка задана процентом): '.$plan['skipped_percent']);
        $this->line('  пропущено (нет действующей скидки): '.$plan['skipped_no_discount']);
        $this->line('  пропущено (скидку менял сам прогон): '.$plan['skipped_discount_touched']);
        $this->line('  пропущено (цена менялась после прогона): '.$plan['skipped_price_moved']);
        $this->line('  товар не найден: '.$plan['missing_products']);
    }
}
