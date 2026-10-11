<?php

namespace App\Shop\Chat;

use App\Jobs\NotifyVisitorAboutOperatorReplyJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Siteko\Chatbot\Chat\ChatMarkdown;
use Siteko\Chatbot\Models\ChatMessage;
use Throwable;

/**
 * Письмо «менеджер ответил» покупателю, который закрыл вкладку.
 *
 * В пакете бота такого письма нет: у виджета платформы почты посетителя
 * не бывает. У магазина она есть у вошедшего в аккаунт, и ответ менеджера,
 * пришедший через полчаса, иначе так и остался бы непрочитанным. Поэтому
 * письмо ставит магазин — наблюдателем `ChatMessage::created`, как платформа
 * ставит своё (bots `AppServiceProvider`).
 *
 * Условия — те же, что были в движке intertooler до пакета.
 */
final class VisitorReplyNotifier
{
    /** Посетитель в чате, если панель отмечалась за последние минуты: письмо ему ни к чему. */
    private const VISITOR_PRESENT_MINUTES = 3;

    /** Не чаще одного письма «менеджер ответил» в час на разговор. */
    private const COOLDOWN_SECONDS = 3600;

    public function __construct(private readonly ChatMarkdown $markdown) {}

    public function schedule(ChatMessage $message): void
    {
        if ($message->role !== ChatMessage::ROLE_OPERATOR) {
            return;
        }

        $conversation = $message->conversation;

        // У анонима почты нет.
        if ($conversation === null || $conversation->user_id === null) {
            return;
        }

        if ($conversation->last_seen_at !== null
            && $conversation->last_seen_at->greaterThan(now()->subMinutes(self::VISITOR_PRESENT_MINUTES))) {
            return;
        }

        if (! Cache::add('chat:visitor-notified:'.$conversation->id, true, self::COOLDOWN_SECONDS)) {
            return;
        }

        try {
            NotifyVisitorAboutOperatorReplyJob::dispatch(
                (int) $conversation->id,
                // Письмо показывает реплику текстом, значит markdown
                // в нём остался бы звёздочками и скобками.
                $this->markdown->toPlainText((string) $message->body),
            )->afterCommit();
        } catch (Throwable $e) {
            Log::warning('Chat visitor notification not queued', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
