<?php

namespace App\Jobs;

use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Mail\ChatEscalationManagerMail;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatEscalationService;
use App\Services\Chat\Contracts\EscalationTarget;
use App\Services\Chat\OperatorPresence;
use App\Services\Notifications\Contracts\EscalationNotifier;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * «Диалог ждёт менеджера» — в три канала сразу.
 *
 * Источник истины ровно один: уведомление в самой админке. Оно не зависит
 * ни от почтового релея, ни от чужого API, и человек, открывший панель,
 * увидит его в любом случае. Почта и MAX — это доставка того же события
 * тому, кто в админку сейчас не смотрит, и обе они best-effort: сбой
 * пишется в лог и глотается, потому что упавшее письмо не должно уносить
 * с собой пуш, а упавший пуш — письмо.
 *
 * Джоба идёт в очередь `default`, а не в ассистентскую: она ничего не
 * считает и в модель не ходит, зато шлёт письма — там же, где остальные
 * рассылки магазина, и её не должен задерживать ответ бота, стоящий
 * впереди в очереди.
 *
 * Кто такой сотрудник и как его зовут, джоба спрашивает у шва
 * `EscalationTarget`: в intertooler это список почт из настроек, во втором
 * магазине заказчика правило может быть другим.
 */
class NotifyManagersAboutEscalationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $trigger,
        public readonly ?string $reason = null,
        /**
         * Присутствие снимается в момент эскалации, а не в момент доставки:
         * отложенный до утра пуш должен помнить, что случилось ночью.
         */
        public readonly bool $operatorsOnline = false,
        /** Пуш в MAX уже откладывался — второй раз откладывать нельзя. */
        public readonly bool $maxDeferred = false,
    ) {}

    public function handle(
        OperatorPresence $presence,
        EscalationNotifier $push,
        EscalationTarget $staff,
    ): void {
        $conversation = ChatConversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        /*
         * Пока джоба ждала своей очереди, разговор могли разобрать: менеджер
         * взял его в работу или закрыл. Тогда уведомление уже опоздало —
         * а ночью, с отложенным до утра пушем, опоздать оно может на часы.
         */
        if ($conversation->isClosed() || ! $conversation->isEscalated()) {
            return;
        }

        $summary = $this->summary($conversation, $staff);
        $url = $this->conversationUrl($conversation);

        if ((bool) config('ai_support.escalation.notify_database', true)) {
            $this->toDatabase($conversation, $staff, $summary, $url);
        }

        if ((bool) config('ai_support.escalation.notify_mail', true)) {
            $this->toMail($conversation, $staff, $url);
        }

        if ((bool) config('ai_support.escalation.notify_max', true)) {
            $this->toMax($presence, $push, $summary, $url);
        }
    }

    /**
     * Уведомление в колокольчике админки. Получатели — те, кто может
     * открыть панель: ролей в проекте нет, доступ определяется списком
     * почт, и он же отвечает на вопрос «кому это показывать».
     */
    private function toDatabase(ChatConversation $conversation, EscalationTarget $staff, string $summary, string $url): void
    {
        try {
            $recipients = collect($staff->panelRecipients());

            if ($recipients->isEmpty()) {
                /*
                 * Самая тихая из возможных аварий: почта в настройке есть,
                 * пользователя с такой почтой нет, уведомление уходит
                 * в никуда и ошибки не будет нигде. Поэтому — в лог.
                 */
                Log::warning('Escalation has no panel recipients', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            FilamentNotification::make()
                ->title($this->title())
                ->body($summary)
                ->icon('heroicon-o-chat-bubble-left-right')
                ->warning()
                ->actions([
                    Action::make('open')
                        ->label('Открыть диалог')
                        ->url($url)
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipients);
        } catch (Throwable $e) {
            Log::warning('Escalation database notification failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function toMail(ChatConversation $conversation, EscalationTarget $staff, string $url): void
    {
        $recipients = $staff->managerEmails();

        if ($recipients === []) {
            Log::warning('Escalation has no manager emails', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $customer = $staff->displayName($conversation->user_id);

        foreach ($recipients as $email) {
            try {
                /*
                 * Новый мейлабл на каждого получателя, а не один на всех:
                 * `Mail::to()` дописывает адрес в тот же объект, и второй
                 * менеджер получил бы письмо с обоими адресами в «Кому»,
                 * а первый — его копию. Так же сделано в рассылке заявок.
                 */
                Mail::to($email)->send(new ChatEscalationManagerMail(
                    conversation: $conversation,
                    trigger: $this->trigger,
                    reason: $this->reason,
                    adminUrl: $url,
                    customer: $customer,
                ));
            } catch (Throwable $e) {
                // Письмо одному менеджеру не должно уносить письмо второму.
                Log::warning('Escalation email failed', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Пуш в мессенджер. Вне смены он откладывается до её начала: разбудить
     * человека в три часа ночи можно, но ответить посетителю всё равно
     * некому — он давно закрыл вкладку, и разговор с ним продолжится
     * не в чате, а письмом по заявке.
     */
    private function toMax(OperatorPresence $presence, EscalationNotifier $push, string $summary, string $url): void
    {
        if (! $push->isConfigured()) {
            return;
        }

        $deferrable = (bool) config('ai_support.escalation.defer_max_until_shift', true);

        if ($deferrable && ! $this->maxDeferred && ! $presence->isOnline()) {
            $start = $presence->nextShiftStart();

            // Смена уже идёт (расписание пустое или мы внутри окна) —
            // откладывать некуда, шлём сейчас.
            if ($start->greaterThan(CarbonImmutable::now($start->timezone))) {
                self::dispatch(
                    $this->conversationId,
                    $this->trigger,
                    $this->reason,
                    $this->operatorsOnline,
                    maxDeferred: true,
                )->delay($start);

                return;
            }
        }

        $push->send($this->title()."\n".$summary, $url);
    }

    private function title(): string
    {
        return match ($this->trigger) {
            ChatEscalationService::TRIGGER_VISITOR => 'Покупатель просит менеджера в чате',
            ChatEscalationService::TRIGGER_CALLBACK => 'Из чата оставили контакты',
            ChatEscalationService::TRIGGER_FAILURE => 'Консультант не смог ответить в чате',
            default => 'Диалог в чате ждёт менеджера',
        };
    }

    /**
     * Короткая выжимка для менеджера: о чём спросили и что случилось.
     * Без неё уведомление означает «сходи посмотри», а с ней половина
     * случаев разбирается, не открывая админку.
     */
    private function summary(ChatConversation $conversation, EscalationTarget $staff): string
    {
        $question = $conversation->messages()
            ->where('role', ChatMessage::ROLE_VISITOR)
            ->orderByDesc('id')
            ->value('body');

        $parts = [];

        if (filled($question)) {
            $parts[] = 'Вопрос: '.Str::limit(trim((string) $question), 300);
        }

        if (filled($this->reason)) {
            $parts[] = 'Причина: '.Str::limit(trim((string) $this->reason), 300);
        }

        if ($conversation->user_id !== null) {
            $parts[] = 'Покупатель: '.$staff->displayName($conversation->user_id);
        }

        if (! $this->operatorsOnline) {
            $parts[] = 'Эскалация пришла вне рабочего времени.';
        }

        return $parts === [] ? 'Разговор передан менеджеру.' : implode("\n", $parts);
    }

    private function conversationUrl(ChatConversation $conversation): string
    {
        try {
            return ChatConversationResource::getUrl('view', ['record' => $conversation->id], panel: 'admin');
        } catch (Throwable) {
            // Панель может быть недоступна из контекста воркера (нет
            // текущего запроса) — ссылка не повод потерять уведомление.
            return url('/admin/chat-conversations/'.$conversation->id);
        }
    }
}
