<?php

test('tooltip component uses tippy smart tap behavior', function () {
    $component = file_get_contents(resource_path('views/components/tooltip.blade.php'));

    expect($component)
        ->toContain('x-tooltip.smart.theme-ks-light')
        ->toContain('data-tooltip-placement')
        ->toContain('data-tooltip-max-width')
        ->toContain('x-ref="tooltipContent"');
});

test('tooltip plugin skips blank content and app js registers overflow tooltip data', function () {
    $plugin = file_get_contents(resource_path('js/plugins/tooltip.js'));
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($plugin)
        ->toContain('const hasContent = () => lastContent.trim().length > 0')
        ->toContain('if (!shouldEnable() || !hasContent())')
        ->toContain('destroyInstance()')
        ->and($appJs)
        ->toContain("const overflowTooltipFactory = (content = '') => ({")
        ->toContain("alpine.data('overflowTooltip', overflowTooltipFactory);");
});
