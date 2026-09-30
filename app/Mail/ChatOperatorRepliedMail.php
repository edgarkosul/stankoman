<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * «Менеджер ответил вам в чате» — письмо покупателю.
 *
 * Нужно потому, что чат на витрине живёт только в открытой вкладке.
 * Менеджер отвечает через двадцать минут, покупатель к этому времени
 * закрыл сайт — и разговор кончается ничем, хотя ответ есть.
 *
 * Уходит не всякий раз: только тому, у кого есть почта (то есть вошедшему
 * в аккаунт), только если его нет в чате прямо сейчас, и не чаще раза
 * в час. Анонима письмом не вернуть вовсе — и это ещё одна причина, по
 * которой основной канал эскалации не живой перехват, а заявка с контактами.
 */
class ChatOperatorRepliedMail extends Mailable
{
    public function __construct(
        /** Ответ менеджера, уже без разметки. */
        public string $preview,
        /** Подписанная ссылка `chat.resume` — живёт неделю. */
        public string $resumeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Менеджер ответил вам в чате');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.chat.operator-replied',
            with: [
                'shopName' => (string) config('settings.general.shop_name', config('app.name')),
                'preview' => Str::limit($this->preview, 400),
                'resumeUrl' => $this->resumeUrl,
            ],
        );
    }
}
