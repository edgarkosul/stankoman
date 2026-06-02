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
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class OrderSubmittedCustomerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress(),
            replyTo: [$this->replyToAddress()],
            subject: 'Ваш заказ №'.$this->order->order_number.' принят',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.orders.submitted_customer_simple',
            text: 'mail.orders.submitted_customer_text',
            with: [
                'order' => $this->order,
                'mailData' => new OrderMailViewData($this->order),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'Auto-Submitted' => 'auto-generated',
                'X-Auto-Response-Suppress' => 'All',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function fromAddress(): Address
    {
        return new Address(
            (string) config('mail.from.address'),
            (string) config('settings.general.shop_name', config('app.name')),
        );
    }

    private function replyToAddress(): Address
    {
        $address = (string) config('company.public_email', config('mail.from.address'));

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $address = (string) config('mail.from.address');
        }

        return new Address($address, (string) config('settings.general.shop_name', config('app.name')));
    }
}
