<?php

namespace App\Services\Chat\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * Кому в магазине говорить, что в чате ждут человека.
 *
 * Шов между чатом и людьми магазина. Чат знает, ЧТО случилось; кто такой
 * сотрудник, откуда берутся его почта и имя — знает магазин
 * (App\Shop\SettingsEscalationTarget). Ролей в intertooler нет, доступ
 * в админку даёт список почт в настройках, и во втором магазине заказчика
 * это правило может быть другим.
 *
 * Здесь же покупатель: его имя нужно уведомлению, а модель пользователя
 * сервисам чата недоступна (сторож — tests/Unit/AiServicesSeamTest.php).
 */
interface EscalationTarget
{
    /**
     * Почты для письма «в чате ждут менеджера». Те же, что у заявок
     * «перезвоните»: менеджер разбирает и то и другое одним движением.
     *
     * @return list<string>
     */
    public function managerEmails(): array;

    /**
     * Получатели уведомления в колокольчике админки — те, кто может её
     * открыть. Возвращаются моделями магазина: Filament шлёт уведомление
     * в базу через Notifiable, и подменить его нечем.
     *
     * @return iterable<int, Notifiable|Model>
     */
    public function panelRecipients(): iterable;

    /**
     * Как назвать покупателя в уведомлении: «Иван Петров (mail@example.com)»
     * или «аноним» для того, кто в аккаунт не входил.
     */
    public function displayName(?int $userId): string;
}
