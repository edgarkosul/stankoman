<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Бэкофилл products.discount_percent из существующей discount_price.
 *
 * Скидка раньше хранилась только как целочисленная цена discount_price, поэтому
 * эффективный процент «плавает» вокруг круглого значения (напр. 4.93 / 5.07 вместо 5)
 * из-за округления до рубля. Команда снапит почти-круглые проценты к ближайшему целому
 * и записывает их в новую колонку-источник истины discount_percent, заодно
 * пересчитывая discount_price. «Некруглые» скидки (как правило — «старые цены»
 * поставщиков) не трогаются — их discount_percent остаётся NULL.
 */
class BackfillProductDiscountPercent extends Command
{
    protected $signature = 'kratonshop:backfill-discount-percent
        {--write : Actually update discount_percent/discount_price in DB (dry-run by default)}
        {--brand=* : Limit to these brands (repeatable), e.g. --brand=Vactool}
        {--tolerance=0.15 : Snap effective percent to nearest integer only if within this tolerance}
        {--chunk=1000 : Chunk size}';

    protected $description = 'Backfill products.discount_percent by snapping near-round effective percents derived from discount_price.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $brands = array_values(array_filter((array) $this->option('brand'), fn ($b) => $b !== null && $b !== ''));
        $tolerance = (float) $this->option('tolerance');
        $chunk = max(1, (int) $this->option('chunk'));

        $inspected = 0;
        $snapped = 0;
        $skippedNotRound = 0;
        $samples = [];

        $query = Product::query()
            ->select(['id', 'slug', 'brand', 'price_amount', 'discount_price', 'discount_percent'])
            ->whereNull('discount_percent')                    // уже выставленные проценты не трогаем
            ->whereNotNull('discount_price')
            ->where('discount_price', '>', 0)
            ->whereColumn('discount_price', '<', 'price_amount')
            ->orderBy('id');

        if ($brands !== []) {
            $query->whereIn('brand', $brands);
        }

        $this->info(($write ? 'WRITE' : 'DRY-RUN').' — scanning products'
            .($brands !== [] ? ' (brands: '.implode(', ', $brands).')' : '').'...');

        $query->chunkById($chunk, function ($rows) use (
            &$inspected, &$snapped, &$skippedNotRound, &$samples, $tolerance, $write
        ): void {
            foreach ($rows as $product) {
                $inspected++;

                $effective = Product::calculateDiscountPercent($product->price_amount, $product->discount_price);
                if ($effective === null) {
                    continue;
                }

                $nearest = (int) round($effective);
                if ($nearest <= 0 || abs($effective - $nearest) > $tolerance) {
                    $skippedNotRound++;

                    continue;
                }

                $newDiscountPrice = Product::calculateDiscountPrice($product->price_amount, $nearest);
                $snapped++;

                if (count($samples) < 20) {
                    $samples[] = sprintf(
                        '#%d %s [%s] price=%d: %.2f%% → %d%% (price %d → %s)',
                        $product->id,
                        $product->slug,
                        $product->brand ?? '—',
                        $product->price_amount,
                        $effective,
                        $nearest,
                        $product->discount_price,
                        $newDiscountPrice === null ? 'null' : (string) $newDiscountPrice,
                    );
                }

                if ($write) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'discount_percent' => $nearest,
                            'discount_price' => $newDiscountPrice,
                        ]);
                }
            }
        });

        $this->newLine();
        $this->line("Inspected (discounted rows): {$inspected}");
        $this->line(($write ? 'Snapped' : 'Would snap').": {$snapped}");
        $this->line("Skipped (not near a round percent, left as-is): {$skippedNotRound}");

        if ($samples !== []) {
            $this->newLine();
            $this->info('Sample changes:');
            foreach ($samples as $s) {
                $this->line('  '.$s);
            }
        }

        if (! $write) {
            $this->newLine();
            $this->warn('Dry-run. Re-run with --write to apply.');
        }

        return self::SUCCESS;
    }
}
