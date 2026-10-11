<?php

namespace App\Shop;

use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Siteko\Chatbot\Chat\Contracts\EscalationTarget;
use Siteko\Chatbot\Chat\Data\EscalationNotice;
use Siteko\Chatbot\Contracts\SettingsStore;
use Siteko\Chatbot\Settings\Keys;

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
 * ищется по таблице, и ошибки при этом не будет нигде. Поэтому каждая
 * эскалация, у которой из списка выпал хоть один адрес, пишет это в лог.
 */
final class SettingsEscalationTarget implements EscalationTarget
{
    public function __construct(private readonly SettingsStore $settings) {}

    /**
     * @return list<string>
     */
    public function managerEmails(): array
    {
        $managers = self::normalize($this->settings->get(Keys::ESCALATION_EMAILS, []));

        // Менеджерских почт нет — письмо уходит админам панели. Иначе
        // единственная незаполненная настройка тихо гасит весь канал.
        return $managers !== [] ? $managers : self::panelEmails();
    }

    /**
     * Колокольчик админки — источник истины об эскалации: письмо и пуш
     * в MAX могут не дойти, а уведомление в панели лежит, пока его не прочли.
     */
    public function notifyPanel(EscalationNotice $notice): void
    {
        $emails = self::panelEmails();
        $recipients = self::panelRecipients($emails);

        $missing = array_values(array_diff($emails, $recipients->pluck('email')->map(
            static fn (mixed $email): string => Str::lower((string) $email),
        )->all()));

        if ($missing !== []) {
            Log::warning('Escalation panel recipients without user rows', [
                'conversation_id' => $notice->conversationId,
                'emails' => $missing,
            ]);
        }

        if ($recipients->isEmpty()) {
            return;
        }

        FilamentNotification::make()
            ->title($notice->title)
            ->body($notice->summary)
            ->icon('heroicon-o-chat-bubble-left-right')
            ->warning()
            ->actions($notice->url === null ? [] : [
                Action::make('open')
                    ->label('Открыть диалог')
                    ->url($notice->url)
                    ->markAsRead(),
            ])
            ->sendToDatabase($recipients);
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

    public function conversationUrl(int $conversationId): ?string
    {
        return ChatConversationResource::getUrl('view', ['record' => $conversationId]);
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
    private static function panelEmails(): array
    {
        return self::normalize(User::filamentAdminEmails());
    }

    /**
     * @param  list<string>  $emails
     * @return Collection<int, User>
     */
    private static function panelRecipients(array $emails): Collection
    {
        if ($emails === []) {
            /** @var Collection<int, User> */
            return new Collection;
        }

        // Регистронезависимость даёт сама колонка (utf8mb4_unicode_ci):
        // LOWER() здесь был бы лишним и заодно выключил бы индекс.
        return User::query()->whereIn('email', $emails)->get();
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
