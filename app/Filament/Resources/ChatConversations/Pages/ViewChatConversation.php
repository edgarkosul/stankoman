<?php

namespace App\Filament\Resources\ChatConversations\Pages;

use App\Filament\Resources\CallbackRequests\CallbackRequestResource;
use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Models\CallbackRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Rules\ValidPhone;
use App\Services\Chat\ChatEscalationService;
use App\Support\CallbackRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Карточка диалога: переписка целиком, ответ оператора и действия над ней.
 *
 * Страница намеренно почти пустая — вся работа идёт внутри
 * `livewire:admin.chat-conversation-panel`. Так лента обновляется опросом
 * сама, не таща за собой перерисовку всей страницы Filament.
 */
class ViewChatConversation extends ViewRecord
{
    protected static string $resource = ChatConversationResource::class;

    protected string $view = 'filament.resources.chat-conversations.view';

    /**
     * Сколько последних вопросов покупателя предлагаем на выбор для статьи.
     *
     * Диалог длиной в полтора десятка реплик — уже редкость, а список
     * длиннее в модальном окне не читается.
     */
    private const KB_QUESTIONS_LIMIT = 15;

    /**
     * Реплики покупателя этого диалога: id → текст в одну строку.
     *
     * Приватное свойство, а не публичное: Livewire возит в снапшоте только
     * публичные, а этот список нужен ровно на время одного запроса.
     *
     * @var array<int, string>|null
     */
    private ?array $visitorQuestions = null;

    public function getTitle(): string
    {
        return 'Диалог №'.$this->getRecord()->getKey();
    }

    /** Панель ответила — обновляем шапку и кнопки. */
    #[On('chat-conversation-updated')]
    public function refreshConversation(): void
    {
        $this->record = $this->getRecord()->fresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('takeOver')
                ->label('Взять в работу')
                ->icon('heroicon-o-hand-raised')
                ->color('primary')
                ->visible(fn (ChatConversation $record): bool => ! $record->isClosed() && ! $record->isOperatorLed())
                ->action(function (ChatConversation $record, ChatEscalationService $escalation): void {
                    $escalation->takeOver($record, (int) Auth::id(), (string) Auth::user()?->name);
                    $this->refreshConversation();

                    Notification::make()
                        ->title('Диалог у вас. Бот в него больше не отвечает.')
                        ->success()
                        ->send();
                }),

            Action::make('returnToBot')
                ->label('Вернуть боту')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Бот снова будет отвечать в этом разговоре, а пометка «ждёт менеджера» снимется.')
                ->visible(fn (ChatConversation $record): bool => ! $record->isClosed()
                    && ($record->isOperatorLed() || $record->isEscalated()))
                ->action(function (ChatConversation $record, ChatEscalationService $escalation): void {
                    $escalation->returnToBot($record, Auth::id());
                    $this->refreshConversation();

                    Notification::make()->title('Разговор снова ведёт бот')->success()->send();
                }),

            Action::make('close')
                ->label('Закрыть')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Переписка останется в истории. Покупатель увидит, что разговор завершён.')
                ->visible(fn (ChatConversation $record): bool => ! $record->isClosed())
                ->action(function (ChatConversation $record, ChatEscalationService $escalation): void {
                    $escalation->close($record, Auth::id());
                    $this->refreshConversation();

                    Notification::make()->title('Диалог закрыт')->success()->send();
                }),

            /*
             * Мост в базу знаний. Оператор дочитал диалог и видит, чего боту
             * не хватило, — здесь эта мысль и превращается в статью, а не
             * откладывается до «зайду потом в раздел и что-нибудь напишу».
             *
             * Вопрос выбирается, а не берётся последний: в переписке их
             * несколько, и «спасибо, до свидания» последним бывает чаще,
             * чем то, ради чего диалог стоит разбирать.
             */
            Action::make('answerInKb')
                ->label('Ответить в базу знаний')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->modalHeading('Ответить в базу знаний')
                ->modalDescription('Вопрос станет заголовком новой статьи. Бот прочитает её и ответит сам, когда спросят о том же снова.')
                ->modalSubmitActionLabel('Открыть форму статьи')
                ->visible(fn (): bool => $this->visitorQuestions() !== [])
                ->schema(fn (): array => [
                    Radio::make('message_id')
                        ->label('Вопрос покупателя')
                        ->options($this->visitorQuestions())
                        // По умолчанию — последний заданный: чаще всего
                        // разбирают именно то, на чём диалог споткнулся.
                        ->default(array_key_last($this->visitorQuestions()))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->redirect(KbArticleResource::getUrl('create', [
                        'from' => (int) $data['message_id'],
                    ]));
                }),

            /*
             * «Завести заявку» — для случая, когда контакт уже прозвучал
             * в переписке («мой номер …», «пишите на …»). Форма в чате
             * заявку из сообщения не создаёт, а менеджеру здесь не нужно
             * просить покупателя повторить то, что он уже написал. Заявка
             * попадает в общий список, то есть в тот же рабочий поток,
             * а не в отдельный «чатовый» угол.
             */
            Action::make('createLead')
                ->label('Завести заявку')
                ->icon('heroicon-o-phone-arrow-down-left')
                ->color('gray')
                ->modalDescription('Заявка появится в «Заявках на звонок» и привяжется к этому диалогу. Письмо менеджерам о ней не уйдёт — вы её и заводите.')
                ->visible(fn (ChatConversation $record): bool => $record->callback_request_id === null)
                ->schema([
                    TextInput::make('name')
                        ->label('Имя')
                        ->maxLength(100)
                        ->default(fn (ChatConversation $record): ?string => $record->user?->name),
                    TextInput::make('phone')
                        ->label('Телефон')
                        ->tel()
                        ->maxLength(32)
                        ->rule(new ValidPhone)
                        ->requiredWithout('email')
                        ->default(fn (ChatConversation $record): ?string => $record->user?->phone),
                    TextInput::make('email')
                        ->label('Почта')
                        ->email()
                        ->maxLength(255)
                        ->requiredWithout('phone')
                        ->default(fn (ChatConversation $record): ?string => $record->user?->email),
                    Textarea::make('comments')
                        ->label('Комментарий')
                        ->rows(3)
                        ->maxLength(1000)
                        ->default(fn (ChatConversation $record): string => 'Из чата, диалог №'.$record->getKey()),
                ])
                ->action(function (ChatConversation $record, array $data, ChatEscalationService $escalation): void {
                    $request = $this->createLead($record, $data);

                    $escalation->note(
                        $record,
                        'Оператор '.Auth::user()?->name.' завёл заявку №'.$request->getKey().' по контактам из переписки.',
                        operatorId: Auth::id(),
                        meta: ['event' => 'callback_created', 'callback_request_id' => $request->getKey()],
                    );

                    $this->refreshConversation();

                    Notification::make()
                        ->title('Заявка создана')
                        ->body('Она в «Продажах» → «Заявки на звонок».')
                        ->success()
                        ->send();
                }),

            Action::make('openLead')
                ->label('Заявка')
                ->icon('heroicon-o-phone')
                ->color('gray')
                ->visible(fn (ChatConversation $record): bool => $record->callback_request_id !== null)
                ->url(fn (ChatConversation $record): string => CallbackRequestResource::getUrl('view', [
                    'record' => $record->callback_request_id,
                ])),
        ];
    }

    /**
     * Заявка напрямую, мимо `CallbackRequestService::submit()`.
     *
     * Сервис бросает событие, а слушатель рассылает письмо менеджерам —
     * но заявку заводит сам менеджер, и письмо самому себе здесь только шум.
     * `notified_at` проставлен сразу: слушатель, если его когда-нибудь позовут
     * по этой заявке, пропустит уже уведомлённую. Хэши считает тот же сервис —
     * по ним работает лимит «одна заявка в минуту на контакт».
     *
     * @param  array<string, mixed>  $data
     */
    private function createLead(ChatConversation $record, array $data): CallbackRequest
    {
        $phone = ValidPhone::normalize((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $email = $email !== '' ? Str::lower($email) : null;

        $request = CallbackRequest::query()->create([
            'user_id' => $record->user_id,
            'name' => trim((string) ($data['name'] ?? '')) ?: null,
            'phone' => $phone,
            'phone_hash' => CallbackRequestService::phoneHash($phone),
            'email' => $email,
            'email_hash' => CallbackRequestService::emailHash($email),
            'comments' => Str::limit(trim((string) ($data['comments'] ?? '')), 1000, '') ?: null,
            'source' => CallbackRequest::SOURCE_CHAT,
            'notified_at' => now(),
        ]);

        $record->forceFill(['callback_request_id' => $request->getKey()])->save();

        return $request;
    }

    /**
     * Последние вопросы покупателя — то, из чего выбирают тему статьи.
     *
     * @return array<int, string> id реплики → текст в одну строку, по времени
     */
    private function visitorQuestions(): array
    {
        return $this->visitorQuestions ??= $this->getRecord()->messages()
            ->where('role', ChatMessage::ROLE_VISITOR)
            ->orderByDesc('id')
            ->limit(self::KB_QUESTIONS_LIMIT)
            ->pluck('body', 'id')
            ->reverse()
            ->map(static fn (string $body): string => Str::limit(
                trim(preg_replace('/\s+/u', ' ', $body) ?? ''),
                140,
            ))
            ->filter(static fn (string $text): bool => $text !== '')
            ->all();
    }
}
