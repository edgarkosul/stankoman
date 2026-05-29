<?php

namespace App\Console\Commands;

use App\Models\LegacyProduct;
use App\Support\Legacy\LegacyProductMatcher;
use Illuminate\Console\Command;

class MatchKratonLegacyProductsCommand extends Command
{
    protected $signature = 'legacy:kraton-match
        {--site=kratonkuban.ru : Legacy source site key}
        {--chunk=500 : Number of legacy rows to process per chunk}';

    protected $description = 'Match imported kratonkuban.ru legacy products to current products';

    public function handle(LegacyProductMatcher $matcher): int
    {
        $site = (string) $this->option('site');
        $chunk = max(1, (int) $this->option('chunk'));

        $inspected = 0;
        $matched = 0;
        $unmatched = 0;

        LegacyProduct::query()
            ->where('source_site', $site)
            ->orderBy('id')
            ->chunkById($chunk, function ($legacyProducts) use ($matcher, &$inspected, &$matched, &$unmatched): void {
                foreach ($legacyProducts as $legacyProduct) {
                    $inspected++;
                    $match = $matcher->match($legacyProduct);

                    if ($match === null) {
                        $legacyProduct->forceFill([
                            'matched_product_id' => null,
                            'match_strategy' => null,
                            'redirect_enabled' => false,
                        ])->save();

                        $unmatched++;

                        continue;
                    }

                    $legacyProduct->forceFill([
                        'matched_product_id' => $match['product']->id,
                        'match_strategy' => $match['strategy'],
                        'redirect_enabled' => true,
                    ])->save();

                    $matched++;
                }
            });

        $this->info("Inspected: {$inspected}");
        $this->info("Matched: {$matched}");
        $this->info("Unmatched: {$unmatched}");

        return self::SUCCESS;
    }
}
