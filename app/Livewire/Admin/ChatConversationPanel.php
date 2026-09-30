<?php

namespace App\Livewire\Admin;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\ShopAssistant;
use App\Services\Ai\Support\PiiRedactor;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatEscalationService;
use App\Services\Chat\ChatMarkdown;
use App\Services\Chat\OperatorPresence;
use App\Services\Chat\PageContext;
use App\Services\Kb\KbVectorStore;
use Filament\Facades\Filament;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Переписка глазами оператора: лента, телеметрия и поле ответа.
 *
 * Админский близнец панели с витрины, но с двумя отличиями, ради которых
 * он и существует отдельным компонентом.
 *
 * Первое: здесь видно ВСЁ — служебные пометки («передал менеджеру»,
 * «взял в работу»), причина остановки хода, вызванные инструменты,
 * найденные фрагменты базы знаний, стоимость и задержка. Посетителю
 * этого не показывают, а оператору без этого не ответить на вопрос
 * «почему бот сказал именно так».
 *
 * Второе: опрос идёт постоянно, пока разговор открыт. На витрине каждый
 * тик — это воркер FPM и таких вкладок могут быть сотни; здесь вкладка
 * одна, и запаздывающий вопрос посетителя дороже лишнего запроса.
 */
class ChatConversationPanel extends Component implements HasForms
{
    use InteractsWithForms;

    #[Locked]
    public int $conversationId;

    public string $draft = '';

    /**
     * Поле ответа оператора — редактор markdown с урезанной панелью.
     *
     * Разметку в ленте рендерит `ChatMarkdown` и для реплики оператора
     * тоже, то есть она работала бы и в голом поле — но узнать о ней было
     * бы неоткуда. Хуже второй край: «цена 5*100*200» превращалась бы
     * у покупателя в курсив, которого никто не просил.
     *
     * КНОПОК РОВНО ПЯТЬ, и это главное решение здесь. У редактора
     * по умолчанию есть ещё заголовки, таблицы, блоки кода и вложения —
     * всё это `ChatMarkdown` выбрасывает намеренно: заголовок в трёх
     * предложениях шум, таблица в узкой панели нечитаема, картинка это
     * запрос на чужой сервер с IP посетителя. Кнопка, чей результат потом
     * молча стирается, ХУЖЕ отсутствующей: оператор считает, что оформил
     * таблицу, а покупатель видит мешанину.
     *
     * Не `RichEditor`: тот хранит HTML, а `body` обязан остаться
     * markdown-текстом — он уходит обратно в модель как история разговора,
     * и HTML там и глупо, и дорого в токенах.
     */
    public function replyForm(Schema $schema): Schema
    {
        return $schema->components([
            MarkdownEditor::make('draft')
                ->label('Ответ покупателю')
                ->hiddenLabel()
                ->placeholder('Ответ покупателю…')
                ->toolbarButtons([
                    ['bold', 'italic', 'link'],
                    ['bulletList', 'orderedList'],
                ])
                // Вложения покупателю не доходят: в ленту едет текст.
                ->fileAttachments(false)
                ->maxLength(4000)
                /*
                 * Пинг «печатает»: throttle читает длительность только в `ms`,
                 * а слушать надо `input`, а не клавиши — тогда ловится и вставка
                 * из буфера, и ввод через IME (у донора обе грабли стоили живого
                 * прогона). Событие contenteditable всплывает до обёртки,
                 * поэтому атрибут работает и на редакторе.
                 */
                ->extraAlpineAttributes(['x-on:input.throttle.3000ms' => '$wire.typing()']),
        ]);
    }

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;

        // Компонент живёт внутри панели, но проверку доступа повторяем:
        // Livewire-эндпоинт открыт отдельно от страницы, на которой
        // компонент отрисован.
        $this->authorizeStaff();
    }

    public function render(ChatConversationService $chat): View
    {
        $this->authorizeStaff();

        $conversation = $this->conversation();

        // Переписки больше нет — показываем это словами и прекращаем
        // опрос: дальше опрашивать нечего.
        if ($conversation === null) {
            return view('livewire.admin.chat-conversation-gone');
        }

        $messages = $this->messages($conversation);

        // Оператор смотрит в разговор — непрочитанного для магазина в нём
        // больше нет. Пишем, только если было что сбрасывать: иначе каждый
        // тик опроса означал бы UPDATE.
        if ($conversation->unread_for_staff > 0) {
            $conversation->forceFill(['unread_for_staff' => 0])->save();
        }

        return view('livewire.admin.chat-conversation-panel', [
            'conversation' => $conversation,
            'messages' => $messages,
            'canReply' => ! $conversation->isClosed(),
            // Та же разметка, что видит покупатель: оператор должен читать
            // ответ бота ровно в том виде, в каком он ушёл в чат.
            'markdown' => app(ChatMarkdown::class),
            /*
             * Бот прямо сейчас готовит ответ на последний вопрос.
             *
             * Оператору это видеть обязательно: без пометки он начинает
             * писать своё, не зная, что через несколько секунд в ленту
             * придёт ответ бота, — и покупатель получает два ответа
             * на один вопрос разными голосами.
             */
            'botPending' => $conversation->isBotLed() && $chat->isPending($conversation),
        ]);
    }

    /**
     * «Оператор набирает ответ» — пометка для посетителя.
     *
     * Отдельным ходом, а не побочным эффектом `wire:model`: набор текста
     * не должен перерисовывать ленту на каждом нажатии. `skipRender()`
     * поэтому обязателен — ответ на этот вызов не содержит разметки вовсе,
     * и вся его цена это запись в кэш.
     */
    public function typing(ChatConversationService $chat): void
    {
        $this->authorizeStaff();
        $this->skipRender();

        $conversation = $this->conversation();

        // Закрытый разговор обещаний не даёт: посетитель в нём уже
        // не ждёт ответа, а увидел бы «менеджер печатает».
        if ($conversation === null || $conversation->isClosed()) {
            return;
        }

        $chat->markStaffTyping($conversation);
    }

    /**
     * Черновик ответа ботом — для оператора, а не вместо него.
     *
     * Бот не отвечает покупателю, он подсказывает оператору, а тот правит
     * и отправляет от себя. Поэтому текст падает в поле ответа и никуда
     * больше — в переписке от этого вызова не появляется ничего.
     *
     * **Побочных действий у черновика нет, и это свойство архитектуры,
     * а не осторожность здесь.** Инструменты `escalate_to_operator`
     * и `request_contact` только ставят флаги в контексте хода; пометку
     * и форму контактов делает вызывающий код, то есть джоба. Значит бот
     * может «позвать человека» внутри черновика, и наружу это не выйдет.
     */
    public function draftWithBot(
        ChatConversationService $chat,
        ShopAssistant $assistant,
        OperatorPresence $presence,
    ): void {
        $this->authorizeStaff();

        $conversation = $this->conversation();

        if ($conversation === null) {
            Notification::make()->title('Переписка удалена — черновик писать не для кого')->warning()->send();

            return;
        }

        if ($conversation->isClosed()) {
            Notification::make()->title('Диалог закрыт — черновик не нужен')->warning()->send();

            return;
        }

        // Набранное не затираем: потерять свой текст хуже, чем нажать
        // кнопку второй раз, очистив поле.
        if (trim($this->draft) !== '') {
            Notification::make()
                ->title('В поле уже есть текст')
                ->body('Очистите его, если хотите черновик от бота: затирать написанное вами он не будет.')
                ->warning()
                ->send();

            return;
        }

        $question = $conversation->messages()
            ->where('role', ChatMessage::ROLE_VISITOR)
            ->where('body', '!=', '')
            ->orderByDesc('id')
            ->first();

        if ($question === null) {
            Notification::make()->title('Покупатель ещё ничего не спросил')->warning()->send();

            return;
        }

        // Тот же расчёт, что у песочницы и черновика статьи: вызов синхронный,
        // а ответ модели с инструментами длится до минуты.
        @set_time_limit(240);

        try {
            $reply = $assistant->ask(
                question: (string) $question->body,
                history: $chat->history($conversation, exceptMessageId: $question->getKey()),
                // Тот же ключ сессии, что у бота в этом разговоре: кэш
                // префикса общий, значит черновик стоит втрое дешевле.
                sessionId: $conversation->promptSessionId(),
                page: PageContext::toPrompt($question->page_context),
                settings: app(AssistantConfig::class)->promptSettings(),
                // Цену бот называет ту, что видит этот покупатель, — как в джобе.
                seesDiscounts: $conversation->user_id !== null,
                operatorsOnline: $presence->isOnline(),
                workingHours: $presence->scheduleSummary(),
            );
        } catch (Throwable $e) {
            Log::warning('operator draft failed', [
                'conversation_id' => $conversation->getKey(),
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Черновик не получился')
                ->body('Шлюз не ответил. Попробуйте ещё раз или напишите сами.')
                ->danger()
                ->send();

            return;
        }

        /*
         * Упавший вызов агент не бросает наружу, а возвращает провалом
         * с причиной `error`. Это не «бот не справился», а «шлюз не ответил»,
         * и путать их нельзя: первое значит «пишите сами», второе — «нажмите
         * ещё раз». Поймано на деве 15.09.2026, когда шлюз минуту не отвечал.
         */
        if ($reply->stopReason === 'error') {
            Notification::make()
                ->title('Черновик не получился')
                ->body('Шлюз не ответил. Попробуйте ещё раз или напишите сами.')
                ->danger()
                ->send();

            return;
        }

        if ($reply->isFailure() || trim($reply->text) === '') {
            /*
             * Ровно тот случай, ради которого оператор и позван: бот уже
             * не справился с этим вопросом. Говорим прямо, а не подсовываем
             * пустое поле с бодрым уведомлением.
             */
            Notification::make()
                ->title('Бот не смог составить ответ')
                ->body('С этим вопросом он не справился и в переписке — отвечать придётся своими словами.')
                ->warning()
                ->send();

            return;
        }

        $this->draft = $reply->text;

        Notification::make()
            ->title('Черновик готов, покупателю ничего не ушло')
            ->body('Проверьте факты и правьте свободно: отправляется он от вашего имени. '
                .'Стоил '.number_format($reply->costRub, 3, ',', ' ').' ₽.')
            ->success()
            ->send();
    }

    /**
     * Ответ оператора. Отдельной кнопки «взять в работу» перед ответом
     * не требуется: написал — значит взял, и статус проставится сам.
     */
    public function send(ChatEscalationService $escalation, ChatConversationService $chat): void
    {
        $this->authorizeStaff();

        $this->validate([
            'draft' => ['required', 'string', 'max:4000'],
        ], [
            'draft.required' => 'Напишите ответ.',
            'draft.max' => 'Слишком длинный ответ.',
        ]);

        $conversation = $this->conversation();

        if ($conversation === null) {
            Notification::make()->title('Переписка удалена — ответить некому')->warning()->send();

            return;
        }

        if ($conversation->isClosed()) {
            Notification::make()->title('Диалог закрыт — отвечать в него нельзя')->warning()->send();

            return;
        }

        $escalation->reply($conversation, (int) Auth::id(), $this->draft);

        // Ответ отправлен — «печатает» снимаем сразу, не дожидаясь, пока
        // истечёт пометка: иначе посетитель ещё десяток секунд ждал бы
        // продолжения, которое уже пришло.
        $chat->clearStaffTyping($conversation);

        $this->draft = '';

        // Шапка страницы показывает статус и набор кнопок — после ответа
        // они другие.
        $this->dispatch('chat-conversation-updated');
    }

    /**
     * «Попросить контакты» — та же форма, что показывает бот.
     *
     * Живому оператору она нужна чаще, чем боту: разговор обрывается,
     * покупатель уходит с вкладки, и написать ему потом некуда.
     *
     * Отдельным сообщением, а не молчаливым флагом: форма, появившаяся
     * сама по себе, выглядит как требование сайта. Строчка от менеджера
     * объясняет, зачем её просят.
     */
    public function askForContacts(ChatEscalationService $escalation, ChatConversationService $chat): void
    {
        $this->authorizeStaff();

        $conversation = $this->conversation();

        if ($conversation === null) {
            Notification::make()->title('Переписка удалена — предлагать некому')->warning()->send();

            return;
        }

        if ($conversation->isClosed()) {
            Notification::make()->title('Диалог закрыт')->warning()->send();

            return;
        }

        if ($conversation->callback_request_id !== null) {
            Notification::make()->title('Контакты уже оставлены')->body('Заявка по этому разговору есть.')->info()->send();

            return;
        }

        $escalation->reply(
            $conversation,
            (int) Auth::id(),
            'Оставьте, пожалуйста, ваши контакты — ответим письмом, даже если разговор прервётся.',
            meta: ['callback_requested' => true],
        );

        $chat->clearStaffTyping($conversation);
        $this->dispatch('chat-conversation-updated');
    }

    /**
     * «В базу знаний» — метка на собственном ответе менеджера.
     *
     * Ролей в магазине нет: тот, кто отвечает в «Диалогах», и тот, кто ведёт
     * базу знаний, — один человек. Значит дешевле пометить удачный ответ
     * на месте, чем через день восстанавливать его по очереди пробелов.
     *
     * Зачем это вообще нужно: три сигнала «Пробелов» оставляет БОТ — плохая
     * оценка, «позвал человека», «не нашёл в базе». В разговоре, который ведёт
     * человек, бота нет, и очередь работ оказалась бы пуста ровно там, где
     * материала больше всего. Этот сигнал ставит человек и приносит с собой
     * готовый ответ.
     *
     * ВЕКТОР СЧИТАЕМ ЗДЕСЬ, а не при разборе очереди. Кластеризация «Пробелов»
     * работает по векторам вопросов; у ответов бота они посчитаны при самом
     * ответе, а у операторской реплики их нет, и без вектора отметка не попала
     * бы ни в одну группу. Один вопрос — это десятки токенов, сотые доли копейки.
     */
    public function markForKb(int $messageId, KbVectorStore $store, PiiRedactor $redactor): void
    {
        $this->authorizeStaff();

        $conversation = $this->conversation();

        $message = $conversation?->messages()
            ->where('id', $messageId)
            ->where('role', ChatMessage::ROLE_OPERATOR)
            ->first();

        if ($message === null) {
            Notification::make()->title('Сообщение не найдено')->warning()->send();

            return;
        }

        // Вопрос — реплика покупателя перед этим ответом. Нет её (менеджер
        // написал первым) — помечать нечего: очередь работ собирается
        // по вопросам, а не по репликам магазина.
        $question = $conversation->messages()
            ->where('role', ChatMessage::ROLE_VISITOR)
            ->where('id', '<', $message->getKey())
            ->orderByDesc('id')
            ->first();

        if ($question === null) {
            Notification::make()
                ->title('Не вижу вопроса перед этим ответом')
                ->body('В базу знаний идут ответы на вопросы покупателей.')
                ->warning()
                ->send();

            return;
        }

        $vector = [];

        try {
            $vector = $store->embedQuery($redactor->redact((string) $question->body));
        } catch (Throwable $e) {
            /*
             * Шлюз недоступен — метку всё равно ставим. Без вектора вопрос
             * не попадёт в группу, но окажется в «Пробелах» отдельной строкой,
             * а ответ менеджера сохранится. Потерять пометку из-за чужой
             * аварии хуже, чем показать её негруппированной.
             */
            Log::warning('Не смог посчитать вектор для метки «в базу знаний»', [
                'message_id' => $message->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $message->forceFill([
            'meta' => array_merge((array) $message->meta, ['to_kb' => true]),
            // Вектор хранится упакованным BLOB, как и у ответов бота: колонка
            // одна на всех, и читает её один и тот же unpackVector().
            'embedding' => $vector === []
                ? $message->getAttribute('embedding')
                : KbVectorStore::packVector($vector),
        ])->save();

        Notification::make()
            ->title('Ответ отправлен в очередь «Пробелы»')
            ->body('Там его можно превратить в статью базы знаний.')
            ->success()
            ->send();
    }

    /**
     * Разговора может не быть, и это штатный исход, а не 404.
     *
     * Две дороги к пустоте: посетитель нажал «Очистить переписку» —
     * его право стереть написанное сильнее нашего удобства, — и ночной
     * `chat:purge`, дошедший до диалога, который у кого-то открыт.
     * С findOrFail обе дороги кончались бы ошибкой прямо в тике опроса,
     * поверх открытой страницы.
     */
    private function conversation(): ?ChatConversation
    {
        return ChatConversation::query()
            ->with(['user', 'operator', 'callbackRequest'])
            ->find($this->conversationId);
    }

    /**
     * Вся переписка, включая служебные пометки.
     *
     * Ограничение в 200 сообщений — страховка от разговора, который
     * посетитель ведёт месяцами: кука вечная, и такой диалог теоретически
     * может вырасти до сотен реплик.
     *
     * @return Collection<int, ChatMessage>
     */
    private function messages(ChatConversation $conversation): Collection
    {
        return $conversation->messages()
            ->withoutEmbedding()
            ->with('operator')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();
    }

    private function authorizeStaff(): void
    {
        $user = Auth::user();

        abort_unless(
            $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')),
            403,
        );
    }
}
