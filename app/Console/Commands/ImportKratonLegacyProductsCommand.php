<?php

namespace App\Console\Commands;

use App\Models\LegacyProduct;
use App\Support\Legacy\KratonProductPageParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class ImportKratonLegacyProductsCommand extends Command
{
    protected $signature = 'legacy:kraton-import
        {--path=/var/www/kratonkuban.ru : Directory containing legacy PHP files}
        {--site=kratonkuban.ru : Legacy source site key}';

    protected $description = 'Import parsed product pages from the abandoned kratonkuban.ru site';

    public function handle(KratonProductPageParser $parser): int
    {
        $path = rtrim((string) $this->option('path'), DIRECTORY_SEPARATOR);
        $site = (string) $this->option('site');

        if (! File::isDirectory($path)) {
            $this->error("Legacy source directory does not exist: {$path}");

            return self::FAILURE;
        }

        $scanned = 0;
        $imported = 0;
        $skipped = 0;

        foreach (File::files($path) as $file) {
            if (! $this->isRootPhpFile($file)) {
                continue;
            }

            $scanned++;
            $product = $parser->parse(File::get($file->getPathname()));

            if ($product === null) {
                $skipped++;

                continue;
            }

            LegacyProduct::query()->updateOrCreate(
                [
                    'source_site' => $site,
                    'source_path' => '/'.$file->getFilename(),
                ],
                $product,
            );

            $imported++;
        }

        $this->info("Scanned: {$scanned}");
        $this->info("Imported: {$imported}");
        $this->info("Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function isRootPhpFile(SplFileInfo $file): bool
    {
        return $file->isFile() && $file->getExtension() === 'php';
    }
}
