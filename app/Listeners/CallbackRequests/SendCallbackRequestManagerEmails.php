<?php

namespace App\Listeners\CallbackRequests;

use App\Events\CallbackRequests\CallbackRequestSubmitted;
use App\Mail\CallbackRequestManagerMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * В отличие от писем заказа, здесь письмо отправляется прямо в слушателе,
 * а не ставится второй задачей в очередь. Иначе `notified_at` значил бы
 * «письмо поставлено в очередь», а в админке нужно «письмо ушло»:
 * у заявки нет номера и письма клиенту, и незамеченная заявка —
 * это покупатель, которому просто никто не позвонил.
 */
class SendCallbackRequestManagerEmails implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $timeout = 30;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function handle(CallbackRequestSubmitted $event): void
    {
        $callbackRequest = $event->callbackRequest->fresh(['product']);

        // Повтор после частичного сбоя не шлёт второе письмо тем, кто его уже получил.
        if ($callbackRequest === null || $callbackRequest->notified_at !== null) {
            return;
        }

        $managerEmails = collect($this->resolveManagerEmails())
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values();

        if ($managerEmails->isEmpty()) {
            Log::warning('SendCallbackRequestManagerEmails: no manager emails', [
                'callback_request_id' => $callbackRequest->id,
            ]);

            $callbackRequest->update([
                'attempts' => $callbackRequest->attempts + 1,
                'last_error' => 'Не заданы адреса менеджеров (general.manager_emails).',
            ]);

            return;
        }

        try {
            foreach ($managerEmails as $managerEmail) {
                Mail::to($managerEmail)->send(new CallbackRequestManagerMail($callbackRequest));
            }
        } catch (Throwable $exception) {
            $callbackRequest->update([
                'attempts' => $callbackRequest->attempts + 1,
                'last_error' => mb_strimwidth($exception->getMessage(), 0, 250),
            ]);

            throw $exception;
        }

        $callbackRequest->update([
            'attempts' => $callbackRequest->attempts + 1,
            'notified_at' => now(),
            'last_error' => null,
        ]);

        Log::info('SendCallbackRequestManagerEmails: sent', [
            'callback_request_id' => $callbackRequest->id,
            'managers_count' => $managerEmails->count(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function resolveManagerEmails(): array
    {
        $managerEmails = config('settings.general.manager_emails', []);
        $rawEmails = [];

        if (is_array($managerEmails)) {
            $rawEmails = $managerEmails;
        } elseif (is_string($managerEmails) && trim($managerEmails) !== '') {
            $rawEmails = [$managerEmails];
        }

        return collect($rawEmails)
            ->map(fn (mixed $email): string => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
