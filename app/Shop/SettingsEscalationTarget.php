<?php

namespace App\Shop;

use App\Models\User;
use App\Services\Chat\Contracts\EscalationTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Сотрудники магазина — те, чьи почты стоят в настройках.
 *
 * Ролей в проекте нет: в админку пускает `User::canAccessPanel()` по списку
 * `general.filament_admin_emails`, и вопрос «кому показывать уведомление
 * о диалоге» имеет ровно один правильный ответ — тем, кто может её открыть.
 * Письма идут по `general.manager_emails`, тем же адресам, что и заявки
 * «перезвоните»: менеджер разбирает то и другое в одном ящике.
 *
 * ⚠️ Список админов и живые пользователи — РАЗНЫЕ вещи. Почта в настройке
 * без строки в `users` уведомление в колокольчике не получит: получатель
 * ищется по таблице. Ошибки при этом не будет нигде — поэтому `ai:kb-doctor`
 * и проверяет пересечение.
 */
final class SettingsEscalationTarget implements EscalationTarget
{
    /**
     * @return list<string>
     */
    public function managerEmails(): array
    {
        $managers = self::normalize(config('settings.general.manager_emails', []));

        // Менеджерских почт нет — письмо уходит админам панели. Иначе
        // единственная незаполненная настройка тихо гасит весь канал.
        return $managers !== [] ? $managers : $this->panelEmails();
    }

    /**
     * @return Collection<int, User>
     */
    public function panelRecipients(): Collection
    {
        $emails = $this->panelEmails();

        if ($emails === []) {
            /** @var Collection<int, User> */
            return new Collection;
        }

        // Регистронезависимость даёт сама колонка (utf8mb4_unicode_ci):
        // LOWER() здесь был бы лишним и заодно выключил бы индекс.
        return User::query()->whereIn('email', $emails)->get();
    }

    public function displayName(?int $userId): string
    {
        if ($userId === null) {
            return 'аноним';
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return 'аноним';
        }

        return filled($user->email)
            ? $user->name.' ('.$user->email.')'
            : (string) $user->name;
    }

    /**
     * Почты тех, кто ходит в админку.
     *
     * Берём их у самой модели, а не собираем из конфига заново: вопрос
     * «кому показывать уведомление» обязан иметь тот же ответ, что и
     * «кого пускать в панель», а там своя подстановка (нет админов —
     * работают менеджеры). Две копии одного правила разошлись бы на первой
     * же правке настроек.
     *
     * @return list<string>
     */
    private function panelEmails(): array
    {
        return User::filamentAdminEmails();
    }

    /**
     * @return list<string>
     */
    private static function normalize(mixed $emails): array
    {
        if (is_string($emails)) {
            $emails = [$emails];
        }

        if (! is_array($emails)) {
            return [];
        }

        return collect($emails)
            ->map(fn (mixed $email): string => Str::lower(trim((string) $email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
