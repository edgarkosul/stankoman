<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\Mail\OrderMailViewData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderSubmittedManagerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->replyToAddress(),
            subject: 'Новый заказ №'.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.submitted_manager',
            with: [
                'order' => $this->order,
                'mailData' => new OrderMailViewData($this->order),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /**
     * @return list<Address>
     */
    private function replyToAddress(): array
    {
        $fromAddress = (string) config('mail.from.address');
        $publicAddress = (string) config('company.public_email', $fromAddress);
        $replyToAddress = $this->isSameDomainAddress($publicAddress, $fromAddress)
            ? $publicAddress
            : $fromAddress;

        if (filter_var($replyToAddress, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }

        return [
            new Address($replyToAddress, (string) config('settings.general.shop_name', config('app.name'))),
        ];
    }

    private function isSameDomainAddress(string $address, string $fromAddress): bool
    {
        if (
            filter_var($address, FILTER_VALIDATE_EMAIL) === false
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
        ) {
            return false;
        }

        return strtolower((string) strrchr($address, '@')) === strtolower((string) strrchr($fromAddress, '@'));
    }
}
