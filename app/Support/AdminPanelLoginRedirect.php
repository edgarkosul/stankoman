<?php

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

/**
 * Витрина не пускает админов намеренно (см. User::canUseStorefront), но ответ
 * «неверные данные» на верный пароль — вранье: человек просто ошибся формой.
 * В этом случае ведём его на вход в панель и объясняем это уже там.
 */
class AdminPanelLoginRedirect
{
    /**
     * Адрес входа в панель, если данные верны, но аккаунт админский.
     */
    public static function resolve(?User $user, string $password): ?string
    {
        if (! $user instanceof User || $user->canUseStorefront()) {
            return null;
        }

        if (! Hash::check($password, (string) $user->password)) {
            return null;
        }

        return Filament::getPanel('admin', isStrict: false)?->getLoginUrl();
    }

    /**
     * Ключ пояснения, которое ждёт человека на странице входа в панель.
     * Во flash кладём ключ, а не текст: переводят его уже в шаблоне.
     */
    public static function statusKey(): string
    {
        return 'auth.panel_only';
    }
}
