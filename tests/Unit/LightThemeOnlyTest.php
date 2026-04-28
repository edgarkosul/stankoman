<?php

use Symfony\Component\Finder\Finder;

function project_path(string $path = ''): string
{
    return dirname(__DIR__, 2).($path !== '' ? DIRECTORY_SEPARATOR.$path : '');
}

test('source does not enable dark theme variants', function (): void {
    $patterns = [
        'Tailwind dark variants' => '/\bdark(?:\\\\)?:/',
        'HTML dark class' => '/class=(["\'])dark(?:\s|\1)/',
        'Flux appearance script' => '/@fluxAppearance\b/',
        'Flux appearance state' => '/(?:\$flux\.appearance|\$flux\.dark|flux\.appearance)/',
        'system dark preference media query' => '/prefers-color-scheme\s*:\s*dark/',
        'dark CSS selector' => '/\.dark(?:\s|\.|:)/',
    ];

    $finder = Finder::create()
        ->files()
        ->in([
            project_path('app'),
            project_path('resources/css'),
            project_path('resources/views'),
        ])
        ->name('*.php')
        ->name('*.blade.php')
        ->name('*.css');

    $matches = [];

    foreach ($finder as $file) {
        $contents = $file->getContents();

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $matches[] = sprintf('%s: %s', $file->getRelativePathname(), $label);
            }
        }
    }

    expect($matches)->toBe([]);
});

test('application forces light color scheme', function (): void {
    expect(file_get_contents(project_path('resources/views/partials/head.blade.php')))
        ->toContain('<meta name="color-scheme" content="light">')
        ->toContain('<meta name="supported-color-schemes" content="light">')
        ->not->toContain('@fluxAppearance');

    expect(file_get_contents(project_path('app/Providers/Filament/AdminPanelProvider.php')))
        ->toContain('->darkMode(false)');

    expect(file_get_contents(project_path('resources/css/app.css')))
        ->toContain('@custom-variant dark (&:where([data-light-theme-only], [data-light-theme-only] *));');

    expect(file_get_contents(project_path('resources/css/filament/admin/theme.css')))
        ->toContain('@custom-variant dark (&:where([data-light-theme-only], [data-light-theme-only] *));');
});
