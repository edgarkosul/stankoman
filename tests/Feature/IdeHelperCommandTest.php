<?php

use Illuminate\Support\Facades\Artisan;

test('registers ide helper generate command', function () {
    expect(Artisan::all())->toHaveKey('ide-helper:generate');
});
