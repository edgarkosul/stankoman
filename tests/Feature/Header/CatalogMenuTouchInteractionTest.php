<?php

test('catalog root links register touch-aware handlers before navigation', function (): void {
    $header = file_get_contents(resource_path('views/components/layouts/partials/header.blade.php'));

    expect($header)
        ->toContain('@pointerdown="prepareTouchActivation($event, {{ $root[\'id\'] }})"')
        ->toContain('@touchstart.passive="prepareTouchActivation($event, {{ $root[\'id\'] }})"')
        ->toContain('@focus="handleFocus({{ $root[\'id\'] }})"')
        ->toContain('@click="handleRootClick($event, {{ $root[\'id\'] }})"')
        ->toContain('@click.outside="catalogOpen = false; resetTouchInteraction()"')
        ->toContain('@keydown.escape.window="catalogOpen = false; resetTouchInteraction()"')
        ->toContain('xs:grid-cols-[280px_minmax(0,1fr)]')
        ->toContain('max-xs:!hidden');
});

test('catalog menu scripts prevent the first touch tap from navigating away', function (): void {
    $viteScript = file_get_contents(resource_path('js/app.js'));
    $inlineFallbackScript = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

    foreach ([$viteScript, $inlineFallbackScript] as $script) {
        expect($script)
            ->toContain('touchFocusRootId')
            ->toContain('armedRootId')
            ->toContain('preventedClickRootId')
            ->toContain('isTouchEnvironment()')
            ->toContain('isBelowXsNavigationBreakpoint()')
            ->toContain("window.matchMedia('(max-width: 479px)').matches")
            ->toContain('prepareTouchActivation(event, id)')
            ->toContain('this.touchFocusRootId === id && now - this.touchFocusStartedAt < 64')
            ->toContain('handleFocus(id)')
            ->toContain('handleRootClick(event, id)')
            ->toContain('this.preventedClickRootId === id')
            ->toContain("window.matchMedia('(hover: none), (pointer: coarse)').matches")
            ->toContain('if (this.isBelowXsNavigationBreakpoint()) {')
            ->toContain('event.preventDefault();')
            ->toContain('event.stopPropagation();')
            ->toContain('this.setActiveInstant(id);');
    }
});
