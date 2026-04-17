<?php

test('filament admin logo links open in a new tab across published panel views', function (): void {
    $projectRoot = dirname(__DIR__, 3);

    $viewPaths = [
        $projectRoot.'/resources/views/vendor/filament-panels/livewire/topbar.blade.php',
        $projectRoot.'/resources/views/vendor/filament-panels/livewire/sidebar.blade.php',
    ];

    foreach ($viewPaths as $viewPath) {
        $contents = file_get_contents($viewPath);

        expect($contents)->toBeString()
            ->toContain('generate_href_html($homeUrl, shouldOpenInNewTab: true)')
            ->toContain('rel="noopener noreferrer"');
    }
});
