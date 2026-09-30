<?php

namespace App\Filament\Pages;

use App\Enums\SettingType;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Setting;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Chat\OperatorPresence;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * «Настройки бота» — то, чем владелец правит бота, не трогая код.
 *
 * Здесь живёт редактируемая половина системного промпта. Вторая половина —
 * гарды: замок идентичности, тематический гейт, запрет выдумывать факты,
 * правила о ценах, — лежит в `SystemPromptBuilder` и в админку не выносится.
 * Причина простая: снесённый гард не ломает бота заметно, он делает его
 * услужливым — тот начинает отвечать на посторонние темы и называть цены,
 * которых посетитель не видит, а обнаруживается это через месяц по жалобе.
 *
 * Предпросмотр показывает СОБРАННЫЙ промпт целиком, вместе с ядром: владелец
 * должен видеть, во что превращаются его абзацы, а не отправлять их вслепую.
 *
 * Расписания менеджеров здесь нет, хотя у донора оно было: режим работы
 * у магазина один на сайт и чат (`company.work_schedule`, решение 15.09.2026),
 * и второе место для того же факта уже однажды разошлось с первым.
 */
class AssistantSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Настройки бота';

    protected static ?string $title = 'Настройки бота';

    protected string $view = 'filament.pages.assistant-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $config = app(AssistantConfig::class);

        $this->form->fill([
            /*
             * Выключатель показывает то, что сохранено в админке, а не итог
             * с аварийным рубильником: иначе при AI_AGENT_ENABLED=false он
             * стоял бы выключенным, и первое же сохранение записало бы
             * «выключен» туда, где владелец ничего не выключал.
             */
            'enabled' => ! $config->disabledByAdmin(),
            'about' => $config->text('about'),
            /*
             * Простой репитер (`->simple()`) держит в состоянии саму строку,
             * а не массив с ключом поля. Обёртка `['text' => …]` доезжала
             * до input'а массивом и рисовалась как «[object Object]» —
             * при этом сохранение работало, поэтому у донора ошибка была
             * видна только глазами на странице.
             */
            'rules' => $config->list('rules'),
            'forbidden_topics' => $config->list('forbidden_topics'),
            /*
             * Сырые значения, а не botName()/invite(): подставится значение
             * по умолчанию — и плейсхолдер в поле перестанет просвечивать,
             * а владелец решит, что значение уже задано им.
             */
            'bot_name' => $config->text('bot_name'),
            'invite' => $config->text('invite'),
            'greeting' => $config->greeting(),
            'refusal' => $config->text('refusal'),
            'escalation' => $config->text('escalation'),
        ]);
    }

    public function form(Schema $form): Schema
    {
        $config = app(AssistantConfig::class);

        return $form
            ->statePath('data')
            ->components([
                Section::make('Работа бота')
                    ->description('Выключенный бот не отвечает никому: в чате покупатель видит форму контактов, а разговоры, которые уже ведёт менеджер, продолжаются как обычно.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Бот отвечает покупателям')
                            ->helperText($this->emergencyOverride()
                                ? 'Сейчас не действует: бот выключен аварийно, строкой в настройках сервера.'
                                : null),
                    ]),

                Section::make('Когда менеджер на связи')
                    ->description(new HtmlString($this->presenceHint())),

                Section::make('О магазине')
                    ->description('Несколько предложений о том, чем магазин занимается и с кем работает. Бот держит это в голове в каждом разговоре.')
                    ->schema([
                        Textarea::make('about')
                            ->label('Описание')
                            ->rows(4)
                            ->maxLength(2000)
                            ->helperText('До 2000 знаков. Факты, а не реклама: бот пересказывает это покупателю как правду о магазине.'),
                    ]),

                Section::make('Правила магазина')
                    ->description('Короткие утверждения, которые бот обязан знать. По одному правилу в строке — так их проще править и видно, что уже сказано. Развёрнутый ответ на отдельный вопрос — это статья, а не правило: правила уходят боту с каждым вопросом все сразу.')
                    ->schema([
                        Repeater::make('rules')
                            /*
                             * Метка спрятана, но задана: `->label('')` Filament
                             * считает её отсутствием и подставляет своё «Rules»
                             * — по-английски и прямо на экране. Скрытая метка
                             * остаётся в разметке для чтения с экрана, поэтому
                             * она должна быть по-русски.
                             */
                            ->label('Правила магазина')
                            ->hiddenLabel()
                            ->addActionLabel('Добавить правило')
                            ->maxItems(AssistantConfig::MAX_RULES)
                            ->reorderable()
                            ->simple(
                                TextInput::make('text')
                                    ->label('Правило')
                                    ->maxLength(300)
                                    ->required()
                                    ->placeholder('Счёт для организации выставляем в день обращения'),
                            ),
                    ]),

                /*
                 * Раздел объясняет СВОЮ границу, а не повторяет общую,
                 * и не приписывает боту знаний, которых у него нет.
                 * Настоящий смысл поля — не тайна, а полномочия: есть темы,
                 * где отвечать должен человек, даже если бот что-то нашёл.
                 * Длинный список вреден: модель читает его как определение
                 * запретного, и всё, чего в нём нет, начинает выглядеть
                 * разрешённым. Правильное состояние поля — пустое или
                 * три-четыре пункта.
                 */
                Section::make('Запретные темы')
                    ->description('Постороннее — погоду, политику, советы не по оборудованию — бот и так не обсуждает: это в его основных правилах. Сюда вписывайте темы про магазин, по которым отвечать должен менеджер, а не бот.')
                    ->schema([
                        TagsInput::make('forbidden_topics')
                            ->label('Запретные темы')
                            ->hiddenLabel()
                            // Плейсхолдер-пример читается как уже введённое
                            // значение — а поле пустое и непонятно, что с ним делать.
                            ->placeholder('Впишите тему и нажмите Enter')
                            // Запятая тоже разделяет: её жмут, когда перечисляют.
                            ->splitKeys(['Enter', ','])
                            ->helperText('Бот знает только страницы сайта, ваши статьи и карточки товаров — внутренних сведений магазина у него нет. Список не прячет секреты, он останавливает разговор там, где слово за человеком: «торг и скидки сверх указанных», «сравнение с конкурентами», «претензии и споры». Держите список коротким и дополняйте по факту из «Диалогов».'),
                    ]),

                Section::make('Формулировки')
                    ->description('Имя бота и слова, которыми он говорит от лица магазина в самых заметных местах разговора.')
                    ->schema([
                        TextInput::make('bot_name')
                            ->label('Как зовут бота')
                            ->maxLength(40)
                            ->placeholder($config->defaultBotName())
                            ->helperText('Имя выходит сразу в четыре места: подсказку у кнопки чата, шапку панели, подпись ответов и ответ самого бота на вопрос «кто ты». Пусто — везде «'.$config->defaultBotName().'». На кнопке чата нарисован робот, так что человеческое имя не выдаёт бота за человека.'),

                        TextInput::make('invite')
                            ->label('Подсказка у кнопки чата')
                            ->maxLength(120)
                            ->placeholder(AssistantConfig::defaultInvite())
                            ->helperText('Одна строка, которую посетитель видит до того, как откроет чат. Это не приветствие в чате — здесь нужна короткая фраза, которую дочитывают.'),

                        Textarea::make('greeting')
                            ->label('Приветствие в пустом чате')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Единственный текст, который покупатель видит до того, как что-то спросит. Не обещайте ответов на то, чего в базе знаний пока нет: бот честно передаст такой вопрос менеджеру.'),

                        Textarea::make('refusal')
                            ->label('Отказ на посторонний вопрос')
                            ->rows(2)
                            ->maxLength(300)
                            ->placeholder('Я консультирую только по вопросам магазина.'),

                        Textarea::make('escalation')
                            ->label('Передача вопроса менеджеру')
                            ->rows(2)
                            ->maxLength(300)
                            ->placeholder('Уточню у менеджера и вернусь с ответом.'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Сохранить')
                ->keyBindings(['mod+s'])
                ->action('save'),

            /*
             * Предпросмотр собирается из СОСТОЯНИЯ ФОРМЫ, а не из сохранённого:
             * смысл в том, чтобы увидеть будущий промпт до записи, иначе
             * проверка правки означает «сохрани и посмотри, что стало с ботом».
             */
            Action::make('preview')
                ->label('Показать промпт')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Системный промпт целиком')
                ->modalDescription('Сначала неизменяемое ядро с гардами, оно живёт в коде. Ваши разделы стоят ниже.')
                ->modalContent(fn (): HtmlString => new HtmlString(
                    '<pre class="whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-4 text-xs leading-relaxed
                                 text-gray-700">'
                    .e($this->previewPrompt())
                    .'</pre>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $config = app(AssistantConfig::class);

        $this->persist([
            'enabled' => (bool) ($state['enabled'] ?? true),
            'about' => trim((string) ($state['about'] ?? '')),
            'rules' => $this->rulesFromForm($state['rules'] ?? []),
            'forbidden_topics' => $this->topicsFromForm($state['forbidden_topics'] ?? []),
            'bot_name' => trim((string) ($state['bot_name'] ?? '')),
            'invite' => trim((string) ($state['invite'] ?? '')),
            'greeting' => trim((string) ($state['greeting'] ?? '')),
            'refusal' => trim((string) ($state['refusal'] ?? '')),
            'escalation' => trim((string) ($state['escalation'] ?? '')),
        ]);

        Notification::make()
            ->title('Настройки сохранены')
            ->body(match (true) {
                $config->enabled() => 'Бот отвечает покупателям. Новые правила действуют со следующего вопроса.',
                $this->emergencyOverride() => 'Настройки записаны, но бот выключен аварийно — покупателям он не ответит, пока разработчик не снимет выключение.',
                default => 'Бот выключен: в чате покупатель видит форму контактов.',
            })
            ->success()
            ->send();
    }

    /**
     * Записать значения в таблицу настроек под префиксом `assistant.`.
     *
     * Здесь, а не в `AssistantConfig`: тот живёт в сервисах ассистента
     * и моделей магазина не знает, а `Setting` — модель магазина.
     *
     * `autoload` = true у всех: их читает каждый ответ бота, и отдельный
     * запрос к базе за каждым ключом на каждом ходе не нужен никому.
     * Кэш настроек сбрасывает сама модель на сохранении — иначе воркер
     * и витрина до конца `rememberForever` жили бы со старыми правилами.
     *
     * @param  array<string, mixed>  $values  ключ без префикса => значение
     */
    private function persist(array $values): void
    {
        foreach ($values as $key => $value) {
            [$stored, $type] = match (true) {
                is_bool($value) => [$value ? '1' : '0', SettingType::Bool],
                is_array($value) => [json_encode(array_values($value), JSON_UNESCAPED_UNICODE), SettingType::Json],
                default => [(string) $value, SettingType::String],
            };

            Setting::query()->updateOrCreate(
                ['key' => AssistantConfig::PREFIX.$key],
                ['value' => $stored, 'type' => $type, 'autoload' => true],
            );

            /*
             * Сохранённое должно действовать в этом же запросе: уведомление
             * ниже спрашивает, включён ли бот, а провайдер разложит настройки
             * заново только на следующем запросе. Поэтому кладём в конфиг руками.
             */
            config()->set('settings.'.AssistantConfig::PREFIX.$key, is_array($value) ? array_values($value) : $value);
        }
    }

    /**
     * Бот выключен аварийно, строкой в `.env`. Тогда выключатель в форме
     * не значит ничего, и сказать об этом надо крупно: иначе владелец будет
     * щёлкать им и не понимать, почему чат молчит.
     */
    public function emergencyOverride(): bool
    {
        return ! (bool) config('ai_support.agent.enabled', true);
    }

    /** Промпт по текущему состоянию формы — то, что получит модель после сохранения. */
    private function previewPrompt(): string
    {
        $state = $this->form->getState();
        $presence = app(OperatorPresence::class);

        return app(SystemPromptBuilder::class)->build(
            settings: array_filter([
                // Имя правит замок идентичности, поэтому обязано попадать
                // в предпросмотр: иначе владелец увидит не тот промпт, что уйдёт.
                'bot_name' => trim((string) ($state['bot_name'] ?? '')),
                'about' => trim((string) ($state['about'] ?? '')),
                'rules' => $this->bullets($this->rulesFromForm($state['rules'] ?? [])),
                'forbidden_topics' => $this->bullets($this->topicsFromForm($state['forbidden_topics'] ?? [])),
                'refusal' => trim((string) ($state['refusal'] ?? '')),
                'escalation' => trim((string) ($state['escalation'] ?? '')),
            ], static fn (string $value): bool => $value !== ''),
            operatorsOnline: $presence->isOnline(),
            workingHours: $presence->scheduleSummary(),
        );
    }

    /** @param  list<string>  $items */
    private function bullets(array $items): string
    {
        return $items === [] ? '' : implode("\n", array_map(static fn (string $i): string => '- '.$i, $items));
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return list<string>
     */
    private function rulesFromForm(array $rules): array
    {
        $texts = [];

        foreach ($rules as $rule) {
            $text = trim((string) (is_array($rule) ? ($rule['text'] ?? '') : $rule));

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @param  array<int, mixed>  $topics
     * @return list<string>
     */
    private function topicsFromForm(array $topics): array
    {
        return array_values(array_filter(array_map(
            static fn ($topic): string => trim((string) $topic),
            $topics,
        ), static fn (string $topic): bool => $topic !== ''));
    }

    /**
     * Живое присутствие словами — и где правится то, от чего оно зависит.
     *
     * Режим работы отвечает на вопрос «как обычно», а обещание бота зависит
     * от «как сейчас»: открытая админка перебивает часы работы. Не сказать
     * об этом здесь — значит оставить владельца править режим работы
     * в уверенности, что он один и решает.
     */
    private function presenceHint(): string
    {
        $presence = app(OperatorPresence::class);
        $state = $presence->resolve();

        $now = $state['online'] ? 'сейчас на связи' : 'сейчас не на связи';
        $source = match ($state['source']) {
            OperatorPresence::SOURCE_OVERRIDE => 'вручную',
            OperatorPresence::SOURCE_ACTIVITY => 'по работе в админке',
            default => 'по режиму работы магазина',
        };

        $scheduleId = Setting::query()->where('key', 'company.work_schedule')->value('id');
        $scheduleUrl = $scheduleId !== null
            ? SettingResource::getUrl('edit', ['record' => $scheduleId])
            : SettingResource::getUrl('index');

        return 'На связи менеджер — бот предлагает позвать его в чат; нет — сразу просит оставить контакты. '
            .'Основа — режим работы магазина (<a href="'.e($scheduleUrl).'" class="text-primary-600 underline">'
            .'«Настройки» → «Режим работы»</a>, '.e($presence->scheduleSummary()).'), тот же, что в шапке сайта. '
            .'Работа в админке перебивает его: пока вы открываете страницы, вы на связи. '
            .'<strong>Менеджер '.e($now).'</strong> — определено '.e($source).'.';
    }
}
