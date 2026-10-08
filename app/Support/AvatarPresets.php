<?php

namespace App\Support;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Готовые аватары бота — для магазина без своего фото или логотипа.
 *
 * Набор из bots (`public/images/avatars`, 07.10.2026), без двух шуточных:
 * webp 256×256, лицо по центру под кружок. Лежат в `public/images/chat-avatars/`
 * и показываются в админке прямо оттуда. Выбранная картинка копируется в папку
 * аватаров на публичном диске: дальше она ничем не отличается от загруженной —
 * тот же путь в настройках и та же уборка при сохранении.
 */
final class AvatarPresets
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? public_path('images/chat-avatars');
    }

    /**
     * Имя картинки → адрес для админки. Пустая папка — пустой список,
     * и кнопки «Выбрать из готовых» в форме нет.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $presets = [];

        foreach (glob($this->directory.'/*.webp') ?: [] as $file) {
            $name = basename($file, '.webp');

            if (self::validName($name)) {
                $presets[$name] = asset('images/chat-avatars/'.$name.'.webp');
            }
        }

        return $presets;
    }

    /**
     * Скопировать картинку на диск и вернуть путь — или null, если такой нет.
     * Имя пришло из браузера: годится только имя файла из набора, без косых
     * черт и точек.
     */
    public function copyTo(string $name, string $disk, string $directory): ?string
    {
        $file = $this->directory.'/'.$name.'.webp';

        if (! self::validName($name) || ! is_file($file)) {
            return null;
        }

        $path = Storage::disk($disk)->putFileAs($directory, new File($file), Str::random(40).'.webp', 'public');

        return is_string($path) ? $path : null;
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[a-z0-9-]{1,40}$/', $name) === 1;
    }
}
