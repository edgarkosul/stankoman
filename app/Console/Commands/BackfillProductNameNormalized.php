<?php

namespace App\Console\Commands;

use App\Support\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProductNameNormalized extends Command
{
    protected $signature = 'kratonshop:backfill-product-name-normalized
        {--write : Actually update name_normalized in DB}
        {--chunk=1000 : Chunk size}';

    protected $description = 'Backfill products.name_normalized from products.name and report duplicates after normalization.';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        $updated = 0;
        $inspected = 0;

        $this->info('Scanning products...');

        DB::table('products')
            ->select(['id', 'name', 'name_normalized'])
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$updated, &$inspected) {
                foreach ($rows as $row) {
                    $inspected++;
                    $norm = NameNormalizer::normalize($row->name);

                    if ($row->name_normalized !== $norm) {
                        if ($this->option('write')) {
                            DB::table('products')
                                ->where('id', $row->id)
                                ->update(['name_normalized' => $norm]);
                        }
                        $updated++;
                    }
                }
            });

        $this->line("Inspected: {$inspected}");
        $this->line(($this->option('write') ? 'Updated' : 'Would update').": {$updated}");

        // Отчёт о возможных дублях (после нормализации)
        $this->info('Checking for duplicates by name_normalized...');
        $dupes = DB::table('products')
            ->select('name_normalized', DB::raw('COUNT(*) as c'))
            ->whereNotNull('name_normalized')
            ->groupBy('name_normalized')
            ->having('c', '>', 1)
            ->orderByDesc('c')
            ->limit(50)
            ->get();

        if ($dupes->isEmpty()) {
            $this->info('No duplicates found. You are safe to add a UNIQUE index.');
        } else {
            $this->warn('Duplicates found (top 50):');
            foreach ($dupes as $d) {
                $this->line("- {$d->name_normalized} — {$d->c} rows");
            }
            $this->warn('Resolve duplicates before creating a UNIQUE index.');
        }

        return 0;
    }
}
