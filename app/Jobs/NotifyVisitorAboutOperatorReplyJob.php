<?php

namespace App\Jobs;

use App\Mail\ChatOperatorRepliedMail;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Вернуть покупателя в разговор письмом «менеджер ответил».
 *
 * Кому писать, решает не джоба: до неё дело доходит, только когда
 * `ChatEscalationService` убедился, что покупатель вошёл в аккаунт, в чате
 * его сейчас нет и за последний час письмо ему не уходило. Здесь остаётся
 * найти почту и собрать подписанную ссылку.
 *
 * Отдельной задачей, а не прямо в сервисе, по той же причине, что и
 * уведомление менеджерам: `App\Models\User` в `app/Services` роняет шов
 * (`tests/Unit/AiServicesSeamTest.php`), да и ответ оператора в админке
 * не должен ждать почтовый релей.
 */
class NotifyVisitorAboutOperatorReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    /** Ссылка из письма живёт неделю: письмо, забытое в почте на полгода, ключом к переписке быть не должно. */
    private const RESUME_LINK_DAYS = 7;

    public function __construct(
        public readonly int $conversationId,
        /** Ответ менеджера, уже без разметки. */
        public readonly string $preview,
    ) {}

    public function handle(): void
    {
        $conversation = ChatConversation::query()->find($this->conversationId);

        if ($conversation === null || $conversation->user_id === null) {
            return;
        }

        $email = User::query()->whereKey($conversation->user_id)->value('email');

        if (blank($email)) {
            return;
        }

        try {
            Mail::to((string) $email)->send(new ChatOperatorRepliedMail(
                preview: $this->preview,
                resumeUrl: URL::temporarySignedRoute(
                    'chat.resume',
                    now()->addDays(self::RESUME_LINK_DAYS),
                    ['token' => $conversation->token],
                ),
            ));
        } catch (Throwable $e) {
            // Письмо — доставка ответа, а не сам ответ: он уже лежит
            // в ленте, и покупатель увидит его, открыв чат.
            Log::warning('Chat visitor notification failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
