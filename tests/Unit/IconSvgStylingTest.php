<?php

test('all project svg icons are styleable with currentColor hooks', function (): void {
    $svgFiles = glob(__DIR__.'/../../resources/svg/*.svg');

    expect($svgFiles)->not->toBeFalse()->not->toBeEmpty();

    foreach ($svgFiles as $svgFile) {
        $svg = file_get_contents($svgFile);

        expect($svg)->not->toBeFalse();
        expect((bool) preg_match('/fill="#|stroke="#/i', (string) $svg))->toBeFalse();
        expect((string) $svg)->toContain('currentColor');
    }
});

test('header and footer x-icon usages style icon layers through tailwind classes', function (): void {
    $header = file_get_contents(__DIR__.'/../../resources/views/components/layouts/partials/header.blade.php');
    $footer = file_get_contents(__DIR__.'/../../resources/views/components/layouts/partials/footer.blade.php');

    expect($header)
        ->toContain('name="spot" class="w-5 h-5 [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red"')
        ->toContain('name="user" class="size-6 xl:size-5 -translate-y-0.5 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red"')
        ->toContain('name="logo_sq" class="size-14 ml-2 xs:hidden [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red [&_.icon-muted]:text-zinc-400 [&_.icon-contrast]:text-white"');

    expect($footer)
        ->toContain('name="max" class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red"')
        ->toContain('name="telegram" class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red"')
        ->toContain('name="phone" class="w-5 h-5 [&_.icon-base]:text-white [&_.icon-accent]:text-brand-red"');
});
