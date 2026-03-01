<?php

use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Tests\TestCase;

uses(TestCase::class);

it('does not derive width from max-width style when rendering html content', function (): void {
    $input = '<p><img height="400px" src="/storage/pics/alubender-op01.jpg" title="Мобильный листогиб Metal Master ALUBENDER" alt="Мобильный листогиб Metal Master ALUBENDER" style="object-fit: contain; max-width: 100%; background-position: center;"></p>';

    $output = RichContentRenderer::make($input)->toUnsafeHtml();

    expect($output)->toContain('height="400px"')
        ->and($output)->toContain('style="height: 400px;"')
        ->and($output)->not->toContain('width="100%"')
        ->and($output)->not->toContain('style="width: 100%');
});

it('keeps explicit width attribute when rendering html content', function (): void {
    $input = '<p><img src="/storage/pics/example.jpg" alt="Example" width="320px" height="180px"></p>';

    $output = RichContentRenderer::make($input)->toUnsafeHtml();

    expect($output)->toContain('width="320px"')
        ->and($output)->toContain('height="180px"');
});
