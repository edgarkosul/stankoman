<?php

namespace App\Listeners\Orders;

use App\Events\Orders\OrderSubmitted;
use App\Mail\OrderSubmittedCustomerMail;
use App\Mail\OrderSubmittedManagerMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOrderSubmittedEmails implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $timeout = 20;

    public function handle(OrderSubmitted $event): void
    {
        $order = $event->order->fresh(['items']);

        if ($order === null) {
            return;
        }

        Log::info('SendOrderSubmittedEmails: start', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);

        if (filter_var((string) $order->customer_email, FILTER_VALIDATE_EMAIL)) {
            Mail::to((string) $order->customer_email)
                ->queue(new OrderSubmittedCustomerMail($order));
        }

        $managerEmails = collect($this->resolveManagerEmails())
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values();

        foreach ($managerEmails as $managerEmail) {
            $mailable = new OrderSubmittedManagerMail($order);

            if (filter_var((string) $order->customer_email, FILTER_VALIDATE_EMAIL)) {
                $mailable->replyTo((string) $order->customer_email);
            }

            Mail::to($managerEmail)->queue($mailable);
        }

        Log::info('SendOrderSubmittedEmails: queued mailables', [
            'order_id' => $order->id,
            'customer_email' => $order->customer_email,
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

        if ($rawEmails === []) {
            $notificationEmail = trim((string) config('settings.general.notification_email', ''));

            if ($notificationEmail !== '') {
                $rawEmails[] = $notificationEmail;
            }
        }

        return collect($rawEmails)
            ->map(fn (mixed $email): string => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
