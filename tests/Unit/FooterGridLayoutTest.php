<?php

test('footer uses responsive grid layout with expected breakpoint behavior', function (): void {
    $component = file_get_contents(__DIR__.'/../../resources/views/components/layouts/partials/footer.blade.php');

    expect($component)
        ->toContain('grid-cols-1')
        ->toContain('md:grid-cols-2')
        ->toContain('lg:grid-cols-3')
        ->toContain('2xl:grid-cols-[1fr_2fr_1fr]')
        ->toContain('md:hidden lg:block')
        ->toContain('<x-footer-menu menu-key="footer" />')
        ->not->toContain('<x-header-menu');
});
