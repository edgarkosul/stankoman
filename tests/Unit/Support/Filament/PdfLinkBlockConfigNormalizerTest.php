<?php

use App\Support\Filament\PdfLinkBlockConfigNormalizer;
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
