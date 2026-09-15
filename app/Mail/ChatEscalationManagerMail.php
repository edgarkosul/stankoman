<?php

namespace App\Mail;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatEscalationService;
use App\Services\Chat\ChatMarkdown;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Письмо менеджеру: в чате ждут человека.
 *
 * По форме — близнец `CallbackRequestManagerMail`, и намеренно: менеджер
 * получает эти письма в один ящик, и заставлять его читать два разных
 * формата ради одного и того же действия («ответить покупателю») незачем.
 *
 * Отличие одно, но существенное: сюда идёт хвост переписки. Заявка
 * самодостаточна, а эскалация без вопроса — это «сходи посмотри», то есть
 * работа, переложенная на получателя.
 *
 * Не ShouldQueue намеренно, как и остальные письма магазина: очередь —
 * это джоба уведомлений, письмо внутри неё ушло бы второй задачей.
 */
class ChatEscalationManagerMail extends Mailable
{
    /** Сколько реплик показывать: разговор, а не вся переписка. */
    private const TRANSCRIPT_LIMIT = 6;

    public function __construct(
        public ChatConversation $conversation,
        public string $trigger,
        public ?string $reason,
        public string $adminUrl,
        /** Покупатель словами магазина: имя с почтой или «аноним». */
        public string $customer,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title().' №'.$this->conversation->id);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.chat.escalation-manager',
            with: [
                'title' => $this->title(),
                'shopName' => (string) config('settings.general.shop_name', config('app.name')),
                'reason' => filled($this->reason) ? Str::limit(trim((string) $this->reason), 300) : null,
                'rows' => $this->rows(),
                'transcript' => $this->transcript(),
                'adminUrl' => $this->adminUrl,
            ],
        );
    }

    /**
     * Обещание в теме совпадает с поводом: «покупатель оставил контакты»
     * и «бот не смог ответить» — разные задачи, и разбирают их по-разному.
     */
    private function title(): string
    {
        return match ($this->trigger) {
            ChatEscalationService::TRIGGER_VISITOR => 'Чат: покупатель просит менеджера',
            ChatEscalationService::TRIGGER_CALLBACK => 'Чат: покупатель оставил контакты',
            ChatEscalationService::TRIGGER_FAILURE => 'Чат: консультант не смог ответить',
            default => 'Чат: вопрос передан менеджеру',
        };
    }

    /**
     * @return list<array{label:string, value:string, url?:string}>
     */
    private function rows(): array
    {
        $this->conversation->loadMissing('callbackRequest');

        return array_values(array_filter([
            ['label' => 'Диалог', 'value' => '№'.$this->conversation->id, 'url' => $this->adminUrl],
            ['label' => 'Покупатель', 'value' => $this->customer],
            $this->callbackRow(),
            [
                'label' => 'Начат',
                'value' => $this->conversation->created_at?->timezone('Europe/Moscow')->format('d.m.Y H:i') ?? '—',
            ],
            ['label' => 'Сообщений', 'value' => (string) $this->conversation->messages_count],
        ]));
    }

    /**
     * @return array{label:string, value:string, url:string}|null
     */
    private function callbackRow(): ?array
    {
        $request = $this->conversation->callbackRequest;

        if ($request === null) {
            return null;
        }

        // Чат просит почту, телефон в нём необязателен: обещать в письме
        // «Телефон» и показать пустоту — значит отправить искать, чего нет.
        return filled($request->phone)
            ? ['label' => 'Телефон из заявки', 'value' => (string) $request->phone, 'url' => 'tel:'.$request->phone]
            : ['label' => 'Почта из заявки', 'value' => (string) $request->email, 'url' => 'mailto:'.$request->email];
    }

    /**
     * Хвост переписки — шесть последних реплик.
     *
     * Служебные пометки («менеджер взял в работу») отфильтрованы: менеджеру
     * нужен разговор, а не журнал переходов, который он и так увидит,
     * открыв диалог.
     *
     * @return list<array{who:string, body:string}>
     */
    private function transcript(): array
    {
        $markdown = app(ChatMarkdown::class);

        return $this->recentMessages()
            ->map(static fn (ChatMessage $message): array => [
                'who' => match ($message->role) {
                    ChatMessage::ROLE_VISITOR => 'Покупатель',
                    ChatMessage::ROLE_OPERATOR => 'Менеджер',
                    default => 'Консультант',
                },
                // Разметку снимаем: письмо показывает переписку текстом,
                // и звёздочки со скобками в нём — мусор.
                'body' => Str::limit($markdown->toPlainText((string) $message->body), 600),
            ])
            ->values()
            ->all();
    }

    /**
     * Шесть последних содержательных реплик, от старой к новой.
     *
     * Если лента уже подложена отношением — берём её оттуда: превью писем
     * рисует диалог по модели, у которой в базе нет ни строки.
     *
     * @return Collection<int, ChatMessage>
     */
    private function recentMessages(): Collection
    {
        $roles = [ChatMessage::ROLE_VISITOR, ChatMessage::ROLE_ASSISTANT, ChatMessage::ROLE_OPERATOR];

        if ($this->conversation->relationLoaded('messages')) {
            return $this->conversation->messages
                ->filter(fn (ChatMessage $message): bool => in_array($message->role, $roles, true) && filled($message->body))
                ->sortByDesc('id')
                ->take(self::TRANSCRIPT_LIMIT)
                ->reverse()
                ->values();
        }

        return $this->conversation->messages()
            ->whereIn('role', $roles)
            ->where('body', '!=', '')
            ->orderByDesc('id')
            ->limit(self::TRANSCRIPT_LIMIT)
            ->get(['id', 'role', 'body'])
            ->reverse()
            ->values();
    }
}
