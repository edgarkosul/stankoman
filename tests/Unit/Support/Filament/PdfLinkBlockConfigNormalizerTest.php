<?php

use App\Support\Filament\PdfLinkBlockConfigNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('downloads remote pdfs and normalizes the block config', function (): void {
    Storage::fake('public');

    Http::fake([
        'https://example.test/files/catalog.pdf' => Http::response(
            "%PDF-1.4\nfake-pdf-content",
            200,
            [
                'Content-Disposition' => 'attachment; filename="catalog.pdf"',
                'Content-Type' => 'application/pdf',
            ],
        ),
    ]);

    $normalized = app(PdfLinkBlockConfigNormalizer::class)->normalize([
        'source_type' => PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL,
        'target' => PdfLinkBlockConfigNormalizer::TARGET_SAME_TAB,
        'url' => 'https://example.test/files/catalog.pdf',
    ]);

    expect($normalized['source_type'])->toBe(PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL)
        ->and($normalized['target'])->toBe(PdfLinkBlockConfigNormalizer::TARGET_SAME_TAB)
        ->and($normalized['url'])->toBe('https://example.test/files/catalog.pdf')
        ->and($normalized['link_text'])->toBe('catalog.pdf')
        ->and($normalized['file'])->toStartWith(PdfLinkBlockConfigNormalizer::DIRECTORY.'/');

    Storage::disk('public')->assertExists($normalized['file']);
    Http::assertSentCount(1);
});

it('normalizes utf8 and spaced pdf urls before downloading', function (): void {
    Storage::fake('public');

    $sourceUrl = 'https://example.test/files/Каталог WARRIOR.pdf';
    $normalizedUrl = 'https://example.test/files/%D0%9A%D0%B0%D1%82%D0%B0%D0%BB%D0%BE%D0%B3%20WARRIOR.pdf';

    Http::fake([
        $normalizedUrl => Http::response(
            "%PDF-1.4\nfake-pdf-content",
            200,
            [
                'Content-Disposition' => 'attachment; filename="Каталог WARRIOR.pdf"',
                'Content-Type' => 'application/pdf',
            ],
        ),
    ]);

    $normalized = app(PdfLinkBlockConfigNormalizer::class)->normalize([
        'source_type' => PdfLinkBlockConfigNormalizer::SOURCE_DOWNLOAD_URL,
        'target' => PdfLinkBlockConfigNormalizer::TARGET_NEW_TAB,
        'url' => $sourceUrl,
    ]);

    expect($normalized['url'])->toBe($normalizedUrl)
        ->and($normalized['link_text'])->toBe('Каталог WARRIOR.pdf')
        ->and($normalized['file'])->toStartWith(PdfLinkBlockConfigNormalizer::DIRECTORY.'/');

    Storage::disk('public')->assertExists($normalized['file']);
    Http::assertSent(function (Request $request) use ($normalizedUrl): bool {
        return $request->url() === $normalizedUrl;
    });
});
