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
}
