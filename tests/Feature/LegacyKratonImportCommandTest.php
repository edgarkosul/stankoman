<?php

use App\Models\LegacyProduct;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('it imports cp1251 kraton product pages', function (): void {
    $directory = legacyKratonFixtureDirectory('imports-product');

    legacyKratonWriteCp1251File($directory.'/spiralny-val-helical-150mm-dlya-w0108-w0109d-w0106fl.php', <<<'HTML'
<div id="TovKart" itemscope itemtype="http://schema.org/Product">
<H1><div itemprop="name">Спиральный вал Helical 150мм для W0108, W0109D, W0106FL</div></H1>
<P><FONT color=#203c69 size=3>Артикул: <FONT color=#800000><STRONG>Helical 150мм</STRONG></FONT>&nbsp;&nbsp;&nbsp;&nbsp; Производитель: <FONT color=#800000><STRONG>Warrior</STRONG></FONT></FONT></P>
</div>
HTML);

    $exitCode = Artisan::call('legacy:kraton-import', [
        '--path' => $directory,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Imported: 1');

    expect(LegacyProduct::query()->first())
        ->source_site->toBe('kratonkuban.ru')
        ->source_path->toBe('/spiralny-val-helical-150mm-dlya-w0108-w0109d-w0106fl.php')
        ->name->toBe('Спиральный вал Helical 150мм для W0108, W0109D, W0106FL')
        ->sku->toBe('Helical 150мм')
        ->manufacturer->toBe('Warrior')
        ->matched_product_id->toBeNull()
        ->match_strategy->toBeNull()
        ->redirect_enabled->toBeFalse();
});

test('it ignores non product php pages', function (): void {
    $directory = legacyKratonFixtureDirectory('ignores-non-products');

    legacyKratonWriteCp1251File($directory.'/category.php', <<<'HTML'
<html><body><h1>Категория</h1></body></html>
HTML);

    $exitCode = Artisan::call('legacy:kraton-import', [
        '--path' => $directory,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Imported: 0')
        ->and(LegacyProduct::query()->count())->toBe(0);
});

test('it updates an existing legacy product row by source path', function (): void {
    $directory = legacyKratonFixtureDirectory('updates-products');
    $file = $directory.'/product.php';

    legacyKratonWriteCp1251File($file, <<<'HTML'
<div id="TovKart" itemscope itemtype="http://schema.org/Product">
<div itemprop="name">Старое имя</div>
<P>Артикул: <FONT><STRONG>OLD-SKU</STRONG></FONT> Производитель: <FONT><STRONG>Old Brand</STRONG></FONT></P>
</div>
HTML);

    Artisan::call('legacy:kraton-import', [
        '--path' => $directory,
    ]);

    legacyKratonWriteCp1251File($file, <<<'HTML'
<div id="TovKart" itemscope itemtype="http://schema.org/Product">
<div itemprop="name">Новое имя</div>
<P>Артикул: <FONT><STRONG>NEW-SKU</STRONG></FONT> Производитель: <FONT><STRONG>New Brand</STRONG></FONT></P>
</div>
HTML);

    $exitCode = Artisan::call('legacy:kraton-import', [
        '--path' => $directory,
    ]);

    expect($exitCode)->toBe(0)
        ->and(LegacyProduct::query()->count())->toBe(1)
        ->and(LegacyProduct::query()->first())
        ->name->toBe('Новое имя')
        ->sku->toBe('NEW-SKU')
        ->manufacturer->toBe('New Brand');
});

function legacyKratonFixtureDirectory(string $name): string
{
    $directory = storage_path("framework/testing/legacy-kraton/{$name}");

    File::deleteDirectory($directory);
    File::ensureDirectoryExists($directory);

    return $directory;
}

function legacyKratonWriteCp1251File(string $path, string $contents): void
{
    File::put($path, mb_convert_encoding($contents, 'Windows-1251', 'UTF-8'));
}
