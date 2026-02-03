<?php

test('tooltip component uses tippy smart tap behavior', function () {
    $component = file_get_contents(resource_path('views/components/tooltip.blade.php'));

    expect($component)
        ->toContain('x-tooltip.smart.theme-ks-light')
        ->toContain('data-tooltip-placement')
        ->toContain('data-tooltip-max-width')
        ->toContain('x-ref="tooltipContent"');
});
