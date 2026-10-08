<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Storage;

/**
 * Как чат выглядит на витрине: кнопка, аватар бота и своя картинка кнопки.
 *
 * Значения живут в общей таблице `settings` под `assistant.` и, как всё
 * остальное оттуда, читаются из конфига: лаунчер стоит на каждой странице
 * витрины, и лишний запрос к базе ради его вида не нужен никому.
 *
 * Только чтение. Пишет страница «Вид чата»: сервисы ассистента моделей
 * магазина (и `Setting` в том числе) не знают — сторож `AiServicesSeamTest`.
 */
final class ChatAppearance
{
    public const DISK = 'public';

    public const DIRECTORY = 'chat-avatars';

    /** Ключи в `settings`: пишет по ним страница, витрина читает из конфига. */
    public const LAUNCHER = 'assistant.launcher';

    public const AVATAR = 'assistant.avatar';

    public const LAUNCHER_PICTURE = 'assistant.launcher_picture';

    /**
     * Прежний выключатель робота («Настройки бота» → «Лицо бота»,
     * 30.09.2026). Читается, только пока кнопку ни разу не сохраняли
     * в «Виде чата»: та при сохранении эту строку убирает.
     */
    public const LEGACY_MASCOT = 'assistant.mascot';

    /** Анимированный робот — пока на кнопке он. */
    public const FACE_ROBOT = 'robot';

    public const FACE_AVATAR = 'avatar';

    /** Значок чата на диске цвета бренда. */
    public const FACE_ICON = 'icon';

    public function launcher(): ChatLauncher
    {
        $raw = config('settings.'.self::LAUNCHER);

        /*
         * Владелец выключил робота старым выключателем и кнопку с тех пор
         * не трогал — значит, на ней значок, как он и выбрал. Без этого
         * выкладка «Вида чата» молча вернула бы робота на витрину.
         */
        if ($raw === null) {
            $mascot = config('settings.'.self::LEGACY_MASCOT);

            if ($mascot !== null && ! (bool) $mascot) {
                return ChatLauncher::from(['image' => ChatLauncher::IMAGE_ICON]);
            }
        }

        return ChatLauncher::from($raw);
    }

    public function avatarPath(): ?string
    {
        return self::path(config('settings.'.self::AVATAR));
    }

    public function launcherPicturePath(): ?string
    {
        return self::path(config('settings.'.self::LAUNCHER_PICTURE));
    }

    /** Адрес аватара или null, если его нет (или файл пропал с диска). */
    public function avatarUrl(): ?string
    {
        return self::url($this->avatarPath());
    }

    /**
     * Картинка на кнопке. Аватар без аватара и своя картинка без картинки —
     * null, и на кнопке значок: пустой кружок хуже облачка.
     */
    public function pictureUrl(?ChatLauncher $launcher = null): ?string
    {
        return match (($launcher ?? $this->launcher())->image) {
            ChatLauncher::IMAGE_AVATAR => $this->avatarUrl(),
            ChatLauncher::IMAGE_CUSTOM => self::url($this->launcherPicturePath()),
            default => null,
        };
    }

    /**
     * Лицо бота в подсказке у кнопки и в шапке панели.
     *
     * Робот — только пока он на кнопке: один собеседник с разными лицами
     * на кнопке и в окне читается как два разных сервиса. Иначе — аватар,
     * если он есть, иначе значок.
     */
    public function face(?ChatLauncher $launcher = null): string
    {
        return match (true) {
            ($launcher ?? $this->launcher())->image === ChatLauncher::IMAGE_ROBOT => self::FACE_ROBOT,
            $this->avatarUrl() !== null => self::FACE_AVATAR,
            default => self::FACE_ICON,
        };
    }

    /**
     * Путь картинки для хранилища: только внутри своей папки, иначе null.
     * Путь из настроек уходит в `delete()` при уборке, и чужой файл с диска
     * так не снести.
     */
    public static function path(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^'.self::DIRECTORY.'/[A-Za-z0-9_-]+\.(png|jpe?g|webp)$~', $value) === 1
            ? $value
            : null;
    }

    /**
     * Уборка папки картинок после сохранения.
     *
     * Прежние аватар и картинку кнопки, если их сменили, — сразу: их больше
     * никто не показывает. Остальное, на что настройки не ссылаются (готовый
     * аватар, выбранный и не сохранённый), — через сутки: в соседней вкладке
     * его, может быть, как раз сейчас сохраняют.
     *
     * @param  list<?string>  $previous
     */
    public function sweep(array $previous): void
    {
        $disk = Storage::disk(self::DISK);
        $current = [$this->avatarPath(), $this->launcherPicturePath()];

        foreach ($previous as $path) {
            if ($path !== null && ! in_array($path, $current, true)) {
                $disk->delete($path);
            }
        }

        foreach ($disk->files(self::DIRECTORY) as $path) {
            if (! in_array($path, $current, true) && $disk->lastModified($path) < now()->subDay()->getTimestamp()) {
                $disk->delete($path);
            }
        }
    }

    private static function url(?string $path): ?string
    {
        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->url($path);
    }
}
