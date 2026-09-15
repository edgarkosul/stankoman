<?php

namespace App\Livewire\Support;

use App\Jobs\GenerateChatReplyJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\AssistantConfig;
use App\Services\Captcha\CaptchaManager;
use App\Services\Chat\AssistantQueueHealth;
use App\Services\Chat\ChatAbuseGuard;
use App\Services\Chat\ChatContactCard;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatEscalationService;
use App\Services\Chat\ChatMarkdown;
use App\Services\Chat\ChatPollingCadence;
use App\Services\Chat\Contracts\LeadIntake;
use App\Services\Chat\Contracts\PageContextSource;
use App\Services\Chat\OperatorPresence;
use App\Support\Products\DiscountVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Панель чата на витрине.
 *
 * Компонент грузится ЛЕНИВО и только после клика по лаунчеру: плейсхолдер
 * лежит в скрытом контейнере, IntersectionObserver на нём не срабатывает,
 * и посетитель, не открывший чат, не делает ни одного лишнего запроса.
 * Это условие, а не оптимизация: виджет висит на каждой странице витрины.
 *
 * Второе такое же условие — бюджет поллинга. Каждый тик `wire:poll`
 * занимает воркер FPM. Поэтому интервал считается по состоянию разговора
 * (ChatPollingCadence), а тик ожидания в типичном случае стоит один GET
 * в Redis: пометка «готовится» на месте — skipRender(), и до базы дело
 * не доходит.
 *
 * Потолки частоты компонент не считает сам: их держит ChatAbuseGuard —
 * один класс на все исходы, проверяемый без базы и без браузера. Здесь
 * только то, что делать с его вердиктом, и это не одно и то же для робота,
 * для человека и для исчерпанного бюджета магазина.
 */
class ChatPanel extends Component
{
    /**
     * Локатор страницы, снятый с маршрута при показе страницы. Дешёвый —
     * никаких запросов к базе — и разворачивается в карточку товара только
     * в момент отправки, чтобы цена и наличие были свежими.
     *
     * #[Locked] обязателен: публичные свойства клиент может менять через
     * `updates` — это штатный механизм для wire:model. Без замка посетитель
     * подставил бы сюда любой локатор и заявил боту, что стоит на чужой
     * карточке.
     *
     * @var array<string, string>|null
     */
    #[Locked]
    public ?array $page = null;

    public string $draft = '';

    /**
     * Токен капчи с первого сообщения. Публичный, потому что его кладёт
     * клиент — но живёт он ровно один запрос: проверили и забыли.
     */
    public string $captchaToken = '';

    /** Ответ бота готовится прямо сейчас. */
    #[Locked]
    public bool $awaiting = false;

    /**
     * Метка начала ожидания — по ней считаются и лесенка, и подсказки.
     * Под замком: иначе клиент проматывает лесенку в любую точку.
     */
    #[Locked]
    public ?int $waitingSince = null;

    /**
     * Текущий интервал wire:poll. Публичный не для клиента, а для DOM:
     * поменять лесенку можно только рендером, поэтому при смене ступени
     * skipRender() не делаем.
     */
    #[Locked]
    public ?string $pollInterval = null;

    /**
     * Отпечаток состояния, которое сейчас на экране у посетителя.
     *
     * Штампуется в render() — то есть описывает ровно то, что клиент
     * видит, — и сверяется на каждом тике опроса. Совпал — до сборки
     * ленты дело не доходит.
     *
     * Под замком: подделав его, клиент заставил бы панель молчать о том,
     * что разговор перешёл к человеку.
     */
    #[Locked]
    public string $stateSignature = '';

    /**
     * Разговор текущего запроса. Приватное — значит не переживает запрос
     * и не уезжает в снапшот: единственный источник опознания это кука,
     * а её мы перечитываем каждый раз. Памятка нужна лишь на один запрос —
     * тот, в котором диалог только что создан, а кука ещё в очереди ответа.
     */
    private ?ChatConversation $conversation = null;

    private bool $conversationResolved = false;

    /**
     * @param  array<string, string>|null  $page
     */
    public function mount(?array $page = null): void
    {
        $this->page = $page;

        $conversation = $this->conversation();

        if ($conversation !== null) {
            $chat = app(ChatConversationService::class);
            $chat->touchSeen($conversation);

            if ($chat->isPending($conversation)) {
                // Вернулись, пока бот ещё думает над прошлым вопросом —
                // подхватываем ожидание, а не показываем тишину.
                $this->awaiting = true;
                $this->waitingSince = $conversation->last_message_at?->getTimestamp() ?? now()->getTimestamp();
            } else {
                /*
                 * Вторая дверь к той же тишине: панель свернули на минуте
                 * ожидания и открыли через час. Поллинг в скрытой панели
                 * не тикает, значит сдачу лесенки отработать было некому,
                 * и без этой ветки посетитель увидел бы свой вопрос
                 * и пустоту под ним.
                 */
                $this->settleStalled($chat, $conversation);
            }
        }

        $this->syncPolling();
    }

    /**
     * Заглушка на время ленивой загрузки.
     *
     * Она обязательна, а не украшательство: `lazy` вешает наблюдатель
     * на плейсхолдер, и пустой div нулевого размера — ненадёжная мишень
     * для IntersectionObserver. Коробка тех же размеров, что и панель,
     * заодно убирает прыжок вёрстки в момент подстановки.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex h-[85vh] w-full items-center justify-center rounded-t-2xl bg-white text-sm
                    text-zinc-400 shadow-2xl ring-1 ring-zinc-200 sm:h-[600px] sm:w-[380px] sm:rounded-2xl">
            Открываю чат…
        </div>
        HTML;
    }

    /**
     * Слушатели — методом, а не атрибутом `#[On]`: имя события формы
     * контактов знает магазин (LeadIntake), и литерал в атрибуте был бы
     * второй копией этого знания.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            app(LeadIntake::class)->createdEvent() => 'linkCallbackRequest',
            'chat-opened' => 'opened',
        ];
    }

    public function render(
        OperatorPresence $presence,
        ChatConversationService $chat,
        AssistantConfig $assistant,
        LeadIntake $lead,
        ChatAbuseGuard $guard,
    ): View {
        $conversation = $this->conversation();
        $messages = $this->messages($conversation);
        $online = $presence->isOnline();

        // Штамп того, что сейчас уедет на экран. Тик опроса сверится
        // с ним и на неизменившемся состоянии не станет собирать ленту.
        $this->stateSignature = $this->currentSignature($conversation, $chat, $online);

        $botEnabled = $assistant->enabled();

        // Подводка и тема карточки контактов выводятся из одной ленты одним
        // правилом — и почему карточка липкая, написано там же.
        $card = ChatContactCard::for($conversation, $messages, $online);

        return view('livewire.support.chat-panel', [
            'conversation' => $conversation,
            'messages' => $messages,
            /*
             * Поле ввода живо, пока есть кому отвечать. Выключенный бот
             * закрывает его — но не в разговоре, который ведёт человек:
             * оператор написал, посетитель читает и не может ответить —
             * худший из возможных исходов.
             */
            'enabled' => $botEnabled || (bool) $conversation?->isOperatorLed(),
            'waitedSeconds' => $this->waitedSeconds(),
            /*
             * Что происходит на той стороне, пока экран молчит. Тишина
             * в разговоре с человеком читается хуже, чем с ботом: бота
             * не ждут лично, а менеджера — да.
             */
            'staffActivity' => $this->staffActivity($conversation, $messages, $chat),
            'callbackHint' => $card->hint,
            'lead' => [
                'component' => $lead->formComponent(),
                'parameters' => $lead->formParameters($card->topic),
            ],
            'operatorsOnline' => $online,
            // Сервис передаётся во вьюху, а не резолвится в ней: шаблон
            // не должен знать про контейнер, а инстанс один на всю ленту.
            'markdown' => app(ChatMarkdown::class),
            'greeting' => $assistant->greeting(),
            /*
             * Имя в шапке. Оно же стоит на кнопке чата и в подсказке,
             * поэтому панель обязана называть бота так же: экран, подписанный
             * одним именем, и собеседник, зовущий себя другим, читаются
             * как подмена.
             */
            'botName' => $assistant->botName(),
            'managerTitle' => trim('Менеджер '.config('settings.general.shop_name')),
            'workingHours' => $presence->scheduleSummary(),
            /*
             * «Позвать менеджера» показываем, только когда за столом
             * действительно кто-то есть. Кнопка, которая зовёт в пустоту, —
             * обещание, которое магазин не сдержит. Вне смены на её месте
             * карточка контактов, а она работает всегда.
             */
            'canCallOperator' => $online
                && $botEnabled
                && ! (bool) $conversation?->isClosed()
                && ! (bool) $conversation?->isOperatorLed()
                && ! (bool) $conversation?->isEscalated(),
            /*
             * Настройки капчи — готовым массивом из одного места на магазин.
             * Разметка не знает ни провайдера, ни ключей: она отдаёт этот
             * массив в Alpine как есть.
             *
             * Проверка нужна только на первом сообщении разговора, поэтому
             * дальше массив приходит выключенным — а выключенный означает,
             * что скрипт капчи в браузер не поедет вовсе. Это не экономия
             * запроса: 124 КБ на каждой странице витрины ради проверки,
             * которая случится один раз за разговор.
             */
            'captcha' => $guard->needsCaptcha($conversation)
                ? app(CaptchaManager::class)->frontendConfig()
                : ['enabled' => false],
        ]);
    }

    /**
     * Отправка с токеном капчи. Клиент зовёт всегда эту, а не `send()`:
     * нужен токен или нет, решает сервер, и знать об этом разметке незачем.
     */
    public function sendWithToken(?string $token = null): void
    {
        $this->captchaToken = (string) $token;

        $this->send(
            app(ChatConversationService::class),
            app(AssistantQueueHealth::class),
            app(ChatEscalationService::class),
            app(AssistantConfig::class),
            app(PageContextSource::class),
            app(ChatAbuseGuard::class),
        );
    }

    public function send(
        ChatConversationService $chat,
        AssistantQueueHealth $health,
        ChatEscalationService $escalation,
        AssistantConfig $assistant,
        PageContextSource $pages,
        ChatAbuseGuard $guard,
    ): void {
        // Длину здесь не проверяем намеренно: обе границы знает намордник,
        // и второй источник того же правила разошёлся бы с ним на первой
        // правке конфига.
        $this->validate([
            'draft' => ['required', 'string'],
        ], [
            'draft.required' => 'Напишите вопрос.',
        ]);

        $conversation = $this->conversation();

        /*
         * Намордник: робот, длина, кулдаун, потолки на разговор и на адрес,
         * дневной бюджет магазина. Стоит до всего остального, потому что
         * всё остальное уже стоит денег или запроса к базе.
         */
        $verdict = $guard->inspect($conversation, $this->draft, isStaff: $this->isStaff());

        // Робот: ни ответа, ни объяснения. Сообщение об ошибке — это
        // подсказка, как обойти проверку.
        if ($verdict->isSilent()) {
            $this->skipRender();

            return;
        }

        if (! $verdict->allowed() && ! $verdict->isBudget()) {
            $this->addError('draft', (string) $verdict->message);

            return;
        }

        /*
         * Капча — только на первом сообщении разговора (см. needsCaptcha).
         * После него посетителя удостоверяет кука, и спрашивать сервис
         * на каждую реплику значило бы платить задержкой и запросом
         * к третьей стороне за уже пройденную проверку.
         */
        if (! $this->passesCaptcha($guard, $conversation)) {
            $this->addError(
                'draft',
                'Не удалось подтвердить, что вы не робот. Обновите страницу и попробуйте ещё раз '
                    .'или оставьте почту — менеджер ответит письмом.',
            );

            return;
        }

        // Бота выключили. Разговор, который ведёт живой оператор, это
        // не касается: ему посетитель пишет по-прежнему.
        if (! $assistant->enabled() && ! (bool) $conversation?->isOperatorLed()) {
            $this->addError('draft', 'Консультант сейчас недоступен. Оставьте почту — менеджер ответит письмом.');

            return;
        }

        // Пока предыдущий ответ готовится, второй вопрос принимать нельзя:
        // это лишний вызов модели за те же деньги и гонка двух джоб.
        if ($this->awaiting) {
            $this->addError('draft', 'Дождитесь ответа на предыдущий вопрос.');

            return;
        }

        // Разговор закрыт — начинаем новый, а не воскрешаем старый:
        // закрытие сделал оператор, и его решение уважаем.
        if ($conversation === null || $conversation->isClosed()) {
            $conversation = $chat->start();
            $guard->rememberNewConversation();
            $this->conversation = $conversation;
            $this->conversationResolved = true;
        }

        $message = $chat->addVisitorMessage(
            $conversation,
            trim($this->draft),
            // Цена в контексте — та, что видит посетитель на карточке: то же
            // правило, по которому карточка решает, показать ли скидку.
            $pages->describe($this->page, DiscountVisibility::allowed()),
        );

        // Счётчики поднимаются здесь, а не в момент проверки: до этой строки
        // вопрос мог не дойти до ленты вовсе, и квоту он бы тратил зря.
        $guard->remember($conversation);

        $this->draft = '';

        // Оператор ведёт разговор — джобу не заводим, сообщение просто ждёт
        // человека: счётчик непрочитанного для магазина уже поднят.
        if (! $conversation->isBotLed()) {
            $this->syncPolling();

            return;
        }

        /*
         * Дневной бюджет магазина исчерпан — бот молчит до полуночи.
         *
         * Отказом это быть не может: вопрос уже в ленте, и оставить его
         * без единого слова — худшее, что можно сделать с покупателем,
         * который ни в чём не виноват. Поэтому исход тот же, что у мёртвой
         * очереди: вопрос уходит менеджеру штатной эскалацией, а чат
         * вырождается в форму обратного звонка.
         *
         * Проверка стоит ЗДЕСЬ, а не рядом с вердиктом: там разговора могло
         * ещё не быть, а эскалировать было бы не в чем.
         */
        if ($verdict->isBudget()) {
            /*
             * Второй и следующие вопросы после исчерпания — молча. Бюджет
             * держится до полуночи, то есть часами, и повторять «передал
             * менеджеру» на каждый вопрос значило бы засыпать ленту
             * одинаковыми репликами, а менеджера — уведомлениями.
             * Состояние посетитель и так видит: под лентой карточка
             * с формой контактов.
             */
            if ($conversation->isEscalated()) {
                $this->syncPolling();

                return;
            }

            $chat->addAssistantMessage(
                $conversation,
                'Передал ваш вопрос менеджеру — он ответит здесь же. '
                    .'Оставьте почту, чтобы он мог ответить и письмом.',
                stopReason: 'daily_budget',
            );
            $escalation->escalate(
                $conversation,
                ChatEscalationService::TRIGGER_FAILURE,
                'Дневной бюджет на ответы бота исчерпан, вопрос передан менеджеру.',
            );
            $this->syncPolling();

            return;
        }

        /*
         * Очередь не разгребается — не делаем вид, что думаем.
         *
         * Без этой проверки посетитель три с половиной минуты смотрит
         * на «печатает» и только потом узнаёт, что ответа не будет.
         * Статус разговора при этом НЕ переводим на оператора: за столом
         * в три часа ночи может не быть никого. Честнее пометить вопрос
         * как ждущий человека и попросить почту.
         */
        if ($health->isStuck()) {
            $chat->addAssistantMessage(
                $conversation,
                'Сейчас консультант не успевает отвечать. Передал ваш вопрос менеджеру — '
                    .'он свяжется с вами. Оставьте почту, чтобы он мог ответить.',
                stopReason: 'queue_stuck',
            );
            $escalation->escalate(
                $conversation,
                ChatEscalationService::TRIGGER_FAILURE,
                'Очередь ответов не разгребается, вопрос передан менеджеру сразу.',
            );
            $this->syncPolling();

            return;
        }

        $chat->markPending($conversation);

        try {
            GenerateChatReplyJob::dispatch($conversation->getKey(), $message->getKey());
        } catch (Throwable $e) {
            // Очередь недоступна — отвечать некому, и ждать нечего.
            // Признаёмся сразу, а не показываем «печатает» до конца лесенки.
            Log::warning('Chat reply job not queued', [
                'conversation_id' => $conversation->getKey(),
                'error' => $e->getMessage(),
            ]);

            $chat->clearPending($conversation);
            $chat->addAssistantMessage(
                $conversation,
                'Не получается ответить прямо сейчас. Оставьте почту — менеджер ответит письмом.',
                stopReason: 'not_queued',
            );
            $escalation->escalate(
                $conversation,
                ChatEscalationService::TRIGGER_FAILURE,
                'Очередь ответов недоступна, вопрос остался без ответа бота.',
            );
            $this->syncPolling();

            return;
        }

        $this->awaiting = true;
        $this->waitingSince = now()->getTimestamp();
        $this->syncPolling();
    }

    /**
     * «Очистить переписку».
     *
     * Удаляет разговор НАСОВСЕМ, вместе с сообщениями (внешний ключ
     * с cascadeOnDelete) и куку в придачу. Мягкое удаление здесь было бы
     * обманом: посетитель просит стереть написанное, а не спрятать его
     * из админки.
     *
     * Что НЕ удаляется — заявка: контакты посетитель оставил отдельным
     * осознанным действием, это обращение в магазин, а не переписка.
     * Внешний ключ смотрит из диалога на заявку, поэтому она переживает
     * удаление сама. И расходная книга: деньги потрачены.
     */
    public function clearHistory(ChatConversationService $chat): void
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            $this->skipRender();

            return;
        }

        $chat->clearPending($conversation);
        $conversation->forceDelete();
        $chat->forgetCookie();

        // Панель остаётся открытой и пустой: приветствие, поле ввода
        // и ничего больше. Новый разговор заведётся на первом же вопросе.
        $this->conversation = null;
        $this->conversationResolved = true;
        $this->awaiting = false;
        $this->waitingSince = null;
        $this->draft = '';
        $this->syncPolling();
    }

    /**
     * «Позвать менеджера».
     *
     * Кнопка видна только в рабочее время (см. render), но проверку
     * присутствия повторяем здесь: между рендером и кликом могла кончиться
     * смена, а Livewire-вызов приходит от клиента и доверять ему нельзя.
     */
    public function callOperator(
        ChatConversationService $chat,
        ChatEscalationService $escalation,
        OperatorPresence $presence,
        ChatAbuseGuard $guard,
    ): void {
        if (! $presence->isOnline()) {
            $this->syncPolling();

            return;
        }

        $conversation = $this->conversation();

        /*
         * Потолки те же, что у вопроса, но без дневного бюджета: зов
         * человека не стоит ни токена, и запрещать его в тот момент, когда
         * бот замолчал, значило бы закрыть посетителю последний выход.
         */
        $verdict = $guard->inspectAction($conversation, isStaff: $this->isStaff());

        if (! $verdict->allowed()) {
            if ($verdict->message !== null) {
                $this->addError('draft', $verdict->message);
            } else {
                $this->skipRender();
            }

            return;
        }

        // Позвали человека, ещё ничего не написав: разговор всё равно нужен —
        // менеджеру есть куда ответить, а посетителю есть где увидеть ответ.
        if ($conversation === null || $conversation->isClosed()) {
            $conversation = $chat->start();
            $guard->rememberNewConversation();
            $this->conversation = $conversation;
            $this->conversationResolved = true;
        }

        if ($conversation->isEscalated() || $conversation->isOperatorLed()) {
            return;
        }

        $escalation->escalate(
            $conversation,
            ChatEscalationService::TRIGGER_VISITOR,
            'Покупатель нажал «Позвать менеджера».',
        );

        $chat->addAssistantMessage(
            $conversation,
            'Передал разговор менеджеру — он скоро подключится. '
                .'Можно и оставить почту: менеджер ответит письмом.',
            stopReason: 'handed_to_operator',
        );

        // Своё же сообщение посетитель видит на экране — непрочитанным
        // оно быть не может.
        $chat->touchSeen($conversation);

        $this->syncPolling();
    }

    /**
     * «Помог ответ?» под репликой бота.
     *
     * Единственный прямой сигнал качества: kb_miss говорит лишь о том,
     * что поиск ничего не нашёл, а эскалация — что бот сам сдался. Уверенно
     * неверный ответ не поднимает ни того, ни другого, и без этой кнопки
     * о нём не узнает никто.
     *
     * Повторный клик по той же кнопке снимает оценку: промахнуться легко,
     * а «отменить» отдельной кнопкой в ленте чата ставить некуда.
     */
    public function rate(int $messageId, int $rating, ChatAbuseGuard $guard): void
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            $this->skipRender();

            return;
        }

        /*
         * Оценка — открытый наружу вызов, который пишет в базу, и потолок
         * ей нужен свой: щедрый, чтобы его не заметил ни один живой человек,
         * и молчаливый, потому что объяснять упёршемуся нечего.
         */
        if (! $guard->allowsRating($conversation)) {
            $this->skipRender();

            return;
        }

        $guard->rememberRating($conversation);

        // Ищем внутри разговора: id приходит от клиента, и оценить чужую
        // переписку по нему быть не должно возможности.
        $message = $conversation->messages()
            ->withoutEmbedding()
            ->whereKey($messageId)
            ->first();

        if ($message === null || ! $message->isRateable()) {
            $this->skipRender();

            return;
        }

        $value = $rating > 0 ? ChatMessage::RATING_UP : ChatMessage::RATING_DOWN;

        $message->forceFill([
            'rating' => $message->rating === $value ? null : $value,
        ])->save();
    }

    /**
     * Посетитель оставил контакты в форме внутри чата.
     *
     * Событие приходит от вложенной формы магазина: она про чат ничего
     * не знает и знать не должна, поэтому связывает заявку с диалогом
     * тот, кто форму встроил. Своя ли это заявка, решает магазин
     * (LeadIntake::claim) — номер приходит от клиента.
     *
     * Зависимости — из контейнера: слушателю события параметры приходят
     * из полезной нагрузки, и подмешивать к ним внедрение — повод для сюрприза.
     */
    public function linkCallbackRequest(int $requestId): void
    {
        $conversation = $this->conversation();

        if ($conversation === null || $conversation->callback_request_id !== null) {
            return;
        }

        $lead = app(LeadIntake::class)->claim($requestId, request()->ip());

        if ($lead === null) {
            return;
        }

        app(ChatEscalationService::class)->attachLead($conversation, $lead);

        $this->syncPolling();
    }

    /**
     * Панель развернули.
     *
     * Пока она свёрнута, поллинг не тикает вовсе — и это условие задачи.
     * Но у уже загруженного компонента лента остаётся той, какой её собрали
     * в последний показ: ответ, пришедший в свёрнутый чат, посетитель увидел
     * бы только на первом тике `wire:poll`, то есть через 4–10 секунд после
     * того, как открыл чат ПО БЕЙДЖУ «вам ответили».
     *
     * Тик тут обычный: если за время простоя ничего не изменилось, `poll()`
     * сверит отпечаток и пропустит рендер.
     */
    public function opened(): void
    {
        $this->poll(app(ChatConversationService::class), app(OperatorPresence::class));
    }

    /**
     * Тик опроса. В типичном случае стоит один GET в Redis: ответ ещё
     * готовится, ступень лесенки та же — рендер пропускаем, и до MySQL
     * дело не доходит вовсе.
     */
    public function poll(ChatConversationService $chat, OperatorPresence $presence): void
    {
        $conversation = $this->conversation();

        if ($conversation !== null) {
            /*
             * Лесенка отработала своё. Ожидание при этом НЕ снимаем здесь:
             * у донора ровно на этом месте был баг — тик, отпускавший поллинг,
             * гасил заодно и признак ожидания, и посетитель оставался
             * с тишиной вместо заглушки.
             */
            if ($this->awaiting && ChatPollingCadence::givenUp($this->waitedSeconds())) {
                $this->settleStalled($chat, $conversation);

                return;
            }

            if ($this->awaiting && $chat->isPending($conversation)) {
                $previous = $this->pollInterval;
                $this->syncPolling();

                // Ступень не сменилась — в DOM менять нечего.
                if ($this->pollInterval === $previous) {
                    $this->skipRender();
                }

                return;
            }
        }

        // Пометки нет: ответ дописан (или джоба умерла и её хук failed()
        // уже написал заглушку).
        $wasWaiting = $this->awaiting;
        $this->awaiting = false;
        $this->waitingSince = null;

        $hadUnread = (int) ($conversation?->unread_for_visitor ?? 0) > 0;

        // Запись в базу — только если было что отмечать. Иначе открытая
        // вкладка писала бы в строку диалога круглые сутки.
        if ($conversation !== null && ($wasWaiting || $hadUnread)) {
            $chat->touchSeen($conversation);
        }

        $previousInterval = $this->pollInterval;
        $this->syncPolling();

        /*
         * Состояние не изменилось — ленту не собираем.
         *
         * Открытая панель обязана опрашивать сервер всегда, иначе до неё
         * не доходит ничего происходящее на другой стороне: оператор взял
         * разговор, ушёл со смены, начал печатать. Но платить полным рендером
         * за каждый такой тик не нужно — почти все они ничего не приносят.
         */
        if (! $wasWaiting
            && ! $hadUnread
            && $this->pollInterval === $previousInterval
            && $this->currentSignature($conversation, $chat, $presence->isOnline()) === $this->stateSignature
        ) {
            $this->skipRender();
        }
    }

    /**
     * Отпечаток всего, что посетитель может увидеть на панели помимо
     * собственных сообщений — включая присутствие сотрудников: от него
     * зависит кнопка «Позвать менеджера», и смена должна доезжать
     * до открытой панели, а не ждать, пока посетитель что-нибудь напишет.
     */
    private function currentSignature(
        ?ChatConversation $conversation,
        ChatConversationService $chat,
        bool $operatorsOnline,
    ): string {
        if ($conversation === null) {
            return 'none|'.($operatorsOnline ? '1' : '0');
        }

        return implode('|', [
            $conversation->status,
            (string) $conversation->escalated_at?->getTimestamp(),
            (string) $conversation->operator_id,
            (string) $conversation->messages_count,
            (string) $conversation->callback_request_id,
            $chat->staffTyping($conversation) ? 'typing' : '',
            $operatorsOnline ? '1' : '0',
        ]);
    }

    /**
     * Чем занят менеджер. Правило — на модели, здесь только сбор данных.
     *
     * @param  Collection<int, ChatMessage>  $messages
     */
    private function staffActivity(
        ?ChatConversation $conversation,
        Collection $messages,
        ChatConversationService $chat,
    ): ?string {
        if ($conversation === null) {
            return null;
        }

        return $conversation->staffActivity(
            typing: $chat->staffTyping($conversation),
            lastMessageRole: $messages->last()?->role,
        );
    }

    /**
     * Ждать больше нечего: либо ответ уже дошёл мимо нашего тика, либо
     * его не будет никогда.
     *
     * К 210-й секунде джоба либо ответила, либо умерла по собственному
     * таймауту в 200 секунд и её хук failed() уже написал заглушку. Если
     * не написал даже он — задачу никто не взял (воркер не поднят), и кнопка
     * «Обновить» не принесла бы ничего, кроме перекладывания нашей аварии
     * на покупателя. Поэтому заглушку пишем сами.
     *
     * Поздняя джоба поверх этой заглушки уже не напишет — она отвечает
     * только пока последнее сообщение принадлежит покупателю.
     */
    private function settleStalled(ChatConversationService $chat, ChatConversation $conversation): void
    {
        /*
         * Разговор ведёт не бот — ждать было нечего, и заглушка здесь ложь.
         * У перехваченного разговора последнее слово штатно остаётся за
         * посетителем: он ждёт ЧЕЛОВЕКА. У донора это стоило четырёх подряд
         * «консультант не смог ответить» в разговоре, который уже вёл живой
         * оператор (07.09.2026).
         */
        if (! $conversation->isBotLed()) {
            $this->stopWaiting();

            return;
        }

        $last = $conversation->messages()->orderByDesc('id')->first();

        // Ответ всё-таки дошёл — просто не на том тике, что мы смотрели.
        if ($last === null || $last->role !== ChatMessage::ROLE_VISITOR) {
            $this->stopWaiting();

            return;
        }

        $chat->addAssistantMessage(
            $conversation,
            'Не получается ответить прямо сейчас. Передал ваш вопрос менеджеру — '
                .'он свяжется с вами. Оставьте почту, чтобы он мог ответить.',
            stopReason: 'stalled',
        );
        app(ChatEscalationService::class)->escalate(
            $conversation,
            ChatEscalationService::TRIGGER_FAILURE,
            'Ответ бота не пришёл за отведённое время.',
        );

        $this->stopWaiting();
    }

    private function stopWaiting(): void
    {
        $this->awaiting = false;
        $this->waitingSince = null;
        $this->syncPolling();
    }

    /**
     * Кука — единственный ключ анонима к переписке; ни id, ни токен
     * от клиента мы не принимаем, поэтому чужой диалог открыть нечем.
     */
    /**
     * Сотрудник магазина.
     *
     * Ему намордник не считает потолки (кроме дневного бюджета): приёмка
     * чата — это десятки разговоров подряд с чисткой переписки между ними.
     * Кто сотрудник — решает тот же список почт, что пускает в админку:
     * ролей в проекте нет.
     */
    private function isStaff(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isFilamentAdmin();
    }

    /**
     * Токен проверяется на сервере — через CaptchaManager, то есть теми же
     * ключами и тем же рубильником, что и остальной магазин.
     *
     * Пустой токен и отказ сервиса посетителя не пускают. Единственное
     * исключение — авария на стороне капчи: недоступный сервис пропускает
     * (см. SmartCaptchaVerifier), и чат тут не делает себе строгости
     * сверх остальных — он единственный, кто в этот момент прикрыт ещё
     * и всем содержимым ChatAbuseGuard.
     */
    private function passesCaptcha(ChatAbuseGuard $guard, ?ChatConversation $conversation): bool
    {
        if (! $guard->needsCaptcha($conversation)) {
            return true;
        }

        return app(CaptchaManager::class)->verify($this->captchaToken, request()->ip());
    }

    private function conversation(): ?ChatConversation
    {
        if ($this->conversationResolved) {
            return $this->conversation;
        }

        $this->conversationResolved = true;

        return $this->conversation = app(ChatConversationService::class)->current();
    }

    /**
     * @return Collection<int, ChatMessage>
     */
    private function messages(?ChatConversation $conversation): Collection
    {
        if ($conversation === null) {
            return collect();
        }

        /*
         * Последние полсотни сообщений, а не вся переписка: диалог живёт
         * годами (кука вечная), и на каждом рендере вытаскивать его целиком —
         * верный способ сделать чат тем тяжелее, чем дольше человек им пользуется.
         */
        return $conversation->messages()
            ->withoutEmbedding()
            ->whereIn('role', [
                ChatMessage::ROLE_VISITOR,
                ChatMessage::ROLE_ASSISTANT,
                ChatMessage::ROLE_OPERATOR,
                // Служебные — ради тех немногих, у которых есть текст
                // для покупателя: смена отвечающего и закрытие разговора.
                ChatMessage::ROLE_SYSTEM,
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            // Отсев здесь, а не в SQL: признак лежит в JSON, и условие
            // по нему стоило бы дороже, чем полсотни строк в памяти.
            ->reject(fn (ChatMessage $message): bool => $message->role === ChatMessage::ROLE_SYSTEM
                && $message->visitorNote() === null)
            ->reverse()
            ->values();
    }

    private function waitedSeconds(): int
    {
        return $this->waitingSince === null ? 0 : max(0, now()->getTimestamp() - $this->waitingSince);
    }

    private function syncPolling(): void
    {
        $conversation = $this->conversation();

        /*
         * panelOpen здесь всегда true, и это не упрощение. Свёрнутый чат
         * скрыт через display:none, а `wire:poll.visible` в скрытом элементе
         * не тикает — состояние «панель закрыта» гасится в браузере и не стоит
         * ни одного запроса. Непрочитанное при свёрнутой панели сторожит
         * лаунчер (/chat/unread).
         */
        $this->pollInterval = ChatPollingCadence::interval(
            awaitingReply: $this->awaiting,
            waitedSeconds: $this->waitedSeconds(),
            panelOpen: true,
            operatorLed: $conversation?->status === ChatConversation::STATUS_OPERATOR,
            escalated: (bool) $conversation?->isEscalated(),
        );
    }
}
