<?php

namespace App\Console\Commands;

use App\Models\LegacyProduct;
use App\Support\Legacy\LegacyProductMatcher;
use Illuminate\Console\Command;

class MatchKratonLegacyProductsCommand extends Command
{
    protected $signature = 'legacy:kraton-match
        {--site=kratonkuban.ru : Legacy source site key}
        {--chunk=500 : Number of legacy rows to process per chunk}
        {--refresh : Recalculate existing unlocked automatic matches}';

    protected $description = 'Match imported kratonkuban.ru legacy products to current products';

    public function handle(LegacyProductMatcher $matcher): int
    {
        $site = (string) $this->option('site');
        $chunk = max(1, (int) $this->option('chunk'));
        $refresh = (bool) $this->option('refresh');

        $inspected = 0;
        $matched = 0;
        $unmatched = 0;
        $skipped = 0;

        LegacyProduct::query()
            ->where('source_site', $site)
            ->where('match_locked', false)
            ->when(! $refresh, fn ($query) => $query->whereNull('matched_product_id'))
            ->orderBy('id')
            ->chunkById($chunk, function ($legacyProducts) use ($matcher, &$inspected, &$matched, &$unmatched): void {
                foreach ($legacyProducts as $legacyProduct) {
                    $inspected++;
                    $match = $matcher->match($legacyProduct);

                    if ($match === null) {
                        $legacyProduct->clearAutomaticMatch();
                        $unmatched++;

                        continue;
                    }

                    $legacyProduct->applyAutomaticMatch($match['product'], $match['strategy']);
                    $matched++;
                }
            });

        $skipped = LegacyProduct::query()
            ->where('source_site', $site)
            ->where(function ($query) use ($refresh): void {
                $query->where('match_locked', true);

                if (! $refresh) {
                    $query->orWhereNotNull('matched_product_id');
                }
            })
            ->count();

        $this->info("Inspected: {$inspected}");
        $this->info("Matched: {$matched}");
        $this->info("Unmatched: {$unmatched}");
        $this->info("Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
