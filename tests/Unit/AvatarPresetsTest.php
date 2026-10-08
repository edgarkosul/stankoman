<?php

use App\Support\AvatarPresets;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

it('отдаёт набор из public/images/chat-avatars', function (): void {
    $presets = (new AvatarPresets)->all();

    expect($presets)->toHaveKey('f1-blonde')
        ->and($presets['f1-blonde'])->toEndWith('/images/chat-avatars/f1-blonde.webp');
});

it('копирует выбранную картинку на диск под новым именем', function (): void {
    Storage::fake('public');

    $path = (new AvatarPresets)->copyTo('m1-beard', 'public', 'chat-avatars');

    expect($path)->toMatch('~^chat-avatars/[A-Za-z0-9]{40}\.webp$~')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('чужое имя не копирует', function (): void {
    Storage::fake('public');

    foreach (['../../.env', 'f1-blonde.webp', 'нет-такого', ''] as $name) {
        expect((new AvatarPresets)->copyTo($name, 'public', 'chat-avatars'))->toBeNull();
    }
});
