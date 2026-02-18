<?php

test('filament tree russian translation file defines required button keys', function (): void {
    $translations = require dirname(__DIR__, 2).'/lang/vendor/filament-tree/ru/filament-tree.php';

    expect($translations)->toBeArray()
        ->toHaveKey('button.expand_all')
        ->toHaveKey('button.collapse_all')
        ->toHaveKey('button.save')
        ->and($translations['button.expand_all'])->toBe('Развернуть все')
        ->and($translations['button.collapse_all'])->toBe('Свернуть все')
        ->and($translations['button.save'])->toBe('Сохранить');
});
