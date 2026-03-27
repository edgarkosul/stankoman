<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\Mail\OrderMailViewData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
            subject: 'Ваш заказ №'.$this->order->order_number.' принят',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.submitted_customer',
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
}
