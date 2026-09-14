<?php

namespace App\Mail;

use App\Filament\Resources\CallbackRequests\CallbackRequestResource;
use App\Models\CallbackRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Не ShouldQueue намеренно: очередь — это слушатель, он и отмечает,
 * что письмо ушло. Queueable-письмо внутри него ушло бы третьей задачей.
 */
class CallbackRequestManagerMail extends Mailable
{
    public function __construct(public CallbackRequest $callbackRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->replyToAddress(),
            subject: $this->title().' №'.$this->callbackRequest->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.callback-requests.manager',
            with: [
                'title' => $this->title(),
                'shopName' => (string) config('settings.general.shop_name', config('app.name')),
                'rows' => $this->rows(),
                'adminUrl' => CallbackRequestResource::getUrl('view', ['record' => $this->callbackRequest], panel: 'admin'),
            ],
        );
    }

    /**
     * Обещание в теме совпадает с каналом: заявка из чата бывает с одной
     * почтой, и «обратный звонок» отправил бы менеджера искать телефон,
     * которого нет.
     */
    private function title(): string
    {
        return filled($this->callbackRequest->phone)
            ? 'Заявка на обратный звонок'
            : 'Заявка на ответ письмом';
    }

    /**
     * @return list<array{label:string, value:string, url?:string}>
     */
    private function rows(): array
    {
        $request = $this->callbackRequest;
        $product = $request->product;

        return array_values(array_filter([
            ['label' => 'Дата', 'value' => $request->created_at?->timezone('Europe/Moscow')->format('d.m.Y H:i') ?? '—'],
            ['label' => 'Откуда', 'value' => $request->sourceLabel()],
            $product !== null ? [
                'label' => 'Товар',
                'value' => (string) $product->name,
                'url' => route('product.show', $product),
            ] : null,
            ['label' => 'Имя', 'value' => $request->name ?: '—'],
            // Пустой контакт не показываем: «Телефон: —» заставляет искать, что сломалось.
            filled($request->phone) ? [
                'label' => 'Телефон',
                'value' => (string) $request->phone,
                'url' => 'tel:'.$request->phone,
            ] : null,
            filled($request->email) ? [
                'label' => 'Почта',
                'value' => (string) $request->email,
                'url' => 'mailto:'.$request->email,
            ] : null,
            filled($request->city) ? ['label' => 'Город', 'value' => (string) $request->city] : null,
            filled($request->call_time) ? ['label' => 'Удобное время', 'value' => (string) $request->call_time] : null,
            filled($request->comments) ? ['label' => 'Комментарий', 'value' => (string) $request->comments] : null,
        ]));
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
