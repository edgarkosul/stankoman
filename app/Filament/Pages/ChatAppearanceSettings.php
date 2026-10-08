<?php

namespace App\Filament\Pages;

use App\Enums\SettingType;
use App\Models\Setting;
use App\Services\Ai\AssistantConfig;
use App\Services\Chat\ChatAppearance;
use App\Services\Chat\ChatLauncher;
use App\Support\AvatarPresets;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use UnitEnum;

/**
 * «Вид чата» — как кнопка чата и лицо бота выглядят на витрине.
 *
 * Перенесено из bots («Виджет», 06.10.2026): справа предпросмотр, который
 * меняется вместе с формой, до сохранения. Смысл экрана — подобрать вид
 * глазами, а не «сохранить и сходить на сайт посмотреть»: сохранённое
 * сразу видят покупатели.
 *
 * Пришло из kratonshop 08.10.2026: там за неделю на проде боту не задали
 * ни одного вопроса, и главным подозреваемым стал анимированный робот —
 * фигура в углу не читается как «спросите консультанта». Робот остаётся
 * одним из вариантов и умолчанием. Прежний выключатель робота («Настройки
 * бота» → «Лицо бота») этот экран заменил.
 *
 * Пишет в `settings` сама страница, а не `ChatAppearance`: сервисы
 * ассистента моделей магазина не знают (`AiServicesSeamTest`).
 *
 * Предпросмотр — не живой чат, а та же разметка и те же стили витрины
 * в iframe без скриптов: в админке стили витрины задели бы Filament.
 */
class ChatAppearanceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'ИИ бот';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Вид чата';

    protected static ?string $title = 'Вид чата';

    protected static ?string $slug = 'chat-appearance';

    protected string $view = 'filament.pages.chat-appearance';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $appearance = app(ChatAppearance::class);
        $launcher = $appearance->launcher();

        $this->form->fill([
            'avatar' => $appearance->avatarPath(),
            'launcher_style' => $launcher->style,
            'launcher_size' => $launcher->size,
            'launcher_image' => $launcher->image,
            'launcher_icon' => $launcher->icon,
            'launcher_picture' => $appearance->launcherPicturePath(),
            // Сырое значение: пустое поле показывает умолчание подсказкой,
            // а не выдаёт его за выбор админа.
            'launcher_status' => $launcher->status === ChatLauncher::DEFAULT_STATUS ? null : $launcher->status,
            'launcher_effect' => $launcher->effect,
            'launcher_dot' => $launcher->dot,
            'launcher_side' => $launcher->side,
            'launcher_x' => $launcher->x,
            'launcher_y' => $launcher->y,
            'launcher_drag' => $launcher->drag,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                $this->launcherSection(),

                Section::make('Аватар бота')
                    ->description('Лицо бота в подсказке у кнопки и в шапке чата — когда на кнопке не робот. Пока на кнопке робот, он же и в подсказке, и в шапке.')
                    ->schema([
                        FileUpload::make('avatar')
                            ->label('Аватар')
                            ->avatar()
                            // Самый крупный показ — кнопка 64 px на экране
                            // с тройной плотностью.
                            ->automaticallyResizeImagesToWidth('256')
                            ->automaticallyResizeImagesToHeight('256')
                            /*
                             * Кадр выбирает админ, а не середина снимка:
                             * неквадратная картинка сразу открывает редактор.
                             * Рамка круглая — на сайте аватар везде кружком.
                             */
                            ->imageEditor()
                            ->circleCropper()
                            ->automaticallyOpenImageEditorForAspectRatio()
                            // Без SVG: файл открывается по прямой ссылке
                            // с домена магазина, а SVG — документ со скриптами.
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->maxSize(1024)
                            ->disk(ChatAppearance::DISK)
                            ->directory(ChatAppearance::DIRECTORY)
                            ->visibility('public')
                            ->live()
                            ->hintAction($this->presetAction())
                            ->helperText('Логотип или лицо. Кадр и масштаб — карандашом на картинке. Без аватара — значок чата. '
                                .'С фотографией человека покупатель может принять бота за живого менеджера — '
                                .'пусть тогда приветствие в «Настройках бота» скажет, что отвечает автоматический консультант.'),
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
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $appearance = app(ChatAppearance::class);
        $previous = [$appearance->avatarPath(), $appearance->launcherPicturePath()];

        $launcher = ChatLauncher::from(self::launcherState($state))->toArray();

        $this->persist(ChatAppearance::LAUNCHER, $launcher === [] ? null : $launcher, SettingType::Json);
        $this->persist(ChatAppearance::AVATAR, ChatAppearance::path(self::file($state['avatar'] ?? null)), SettingType::String);
        $this->persist(ChatAppearance::LAUNCHER_PICTURE, ChatAppearance::path(self::file($state['launcher_picture'] ?? null)), SettingType::String);

        /*
         * Старый выключатель робота больше не нужен: выбор теперь здесь.
         * Оставь его — и сохранённый робот (умолчание, строки нет) снова
         * уступил бы значку по прежней строке «робот выключен».
         */
        $this->persist(ChatAppearance::LEGACY_MASCOT, null, SettingType::Bool);

        $appearance->sweep($previous);

        Notification::make()
            ->success()
            ->title('Вид чата сохранён')
            ->body('Покупатели увидят новую кнопку на следующей открытой странице.')
            ->send();
    }

    /**
     * Документ предпросмотра — для `srcdoc` iframe. Собирается из СОСТОЯНИЯ
     * ФОРМЫ, а не из сохранённого: ничего не пишет.
     */
    public function previewDocument(): string
    {
        $state = $this->form->getRawState();
        $assistant = app(AssistantConfig::class);

        $launcher = ChatLauncher::from(self::launcherState($state));
        $avatarUrl = $this->previewImageUrl($state['avatar'] ?? null);
        $picture = match ($launcher->image) {
            ChatLauncher::IMAGE_AVATAR => $avatarUrl,
            ChatLauncher::IMAGE_CUSTOM => $this->previewImageUrl($state['launcher_picture'] ?? null),
            default => null,
        };

        return view('filament.chat-appearance-preview', [
            'launcher' => $launcher,
            'picture' => $picture,
            'avatarUrl' => $avatarUrl,
            'face' => match (true) {
                $launcher->image === ChatLauncher::IMAGE_ROBOT => ChatAppearance::FACE_ROBOT,
                $avatarUrl !== null => ChatAppearance::FACE_AVATAR,
                default => ChatAppearance::FACE_ICON,
            },
            'botName' => $assistant->botName(),
            'botInvite' => $assistant->invite(),
            'botOnline' => $assistant->enabled(),
        ])->render();
    }

    /**
     * «Кнопка чата»: вид, что на ней, эффект, точка, угол. Всё живое —
     * предпросмотр показывает кнопку сразу, до сохранения.
     */
    private function launcherSection(): Section
    {
        $floatingRobot = static fn (Get $get): bool => $get('launcher_image') === ChatLauncher::IMAGE_ROBOT
            && $get('launcher_style') !== ChatLauncher::STYLE_PILL;

        return Section::make('Кнопка чата')
            ->description('Кнопка в углу витрины, которая открывает чат. Предпросмотр справа меняется сразу, на сайте — после сохранения.')
            ->columns(2)
            ->schema([
                ToggleButtons::make('launcher_image')
                    ->label('На кнопке')
                    ->options(ChatLauncher::IMAGES)
                    ->icons([
                        ChatLauncher::IMAGE_ROBOT => Heroicon::OutlinedCpuChip,
                        ChatLauncher::IMAGE_ICON => Heroicon::OutlinedChatBubbleLeft,
                        ChatLauncher::IMAGE_AVATAR => Heroicon::OutlinedUserCircle,
                        ChatLauncher::IMAGE_CUSTOM => Heroicon::OutlinedPhoto,
                    ])
                    ->inline()
                    ->default(ChatLauncher::IMAGE_ROBOT)
                    ->live()
                    ->columnSpanFull()
                    ->helperText(fn (Get $get): ?string => match (true) {
                        $get('launcher_image') === ChatLauncher::IMAGE_ROBOT => 'Анимированный робот — так кнопка выглядела до этих настроек. Он же — в подсказке и в шапке чата.',
                        $get('launcher_image') === ChatLauncher::IMAGE_AVATAR && blank($get('avatar')) => 'Аватар не загружен (ниже, в «Аватаре бота») — пока его нет, на кнопке значок.',
                        $get('launcher_image') === ChatLauncher::IMAGE_AVATAR => 'Тот же аватар, что в подсказке и в шапке чата.',
                        default => null,
                    }),

                ToggleButtons::make('launcher_style')
                    ->label('Вид')
                    ->options(ChatLauncher::STYLES)
                    ->icons([
                        ChatLauncher::STYLE_CIRCLE => Heroicon::OutlinedStopCircle,
                        ChatLauncher::STYLE_PILL => Heroicon::OutlinedUserCircle,
                    ])
                    ->inline()
                    ->default(ChatLauncher::STYLE_CIRCLE)
                    ->live()
                    ->helperText('С именем — картинка, имя бота и строка под ним. На телефоне кнопка всё равно кружок: иначе закрыла бы полэкрана.'),

                ToggleButtons::make('launcher_size')
                    ->label('Размер')
                    ->options(ChatLauncher::SIZES)
                    ->inline()
                    ->default('m')
                    ->live()
                    ->hidden($floatingRobot)
                    ->helperText('48, 56 или 64 пикселя.'),

                // Значки в кнопках выбора — ближайшие Heroicons к контурам на сайте.
                ToggleButtons::make('launcher_icon')
                    ->label('Значок')
                    ->options(ChatLauncher::ICONS)
                    ->icons([
                        'chat' => Heroicon::OutlinedChatBubbleOvalLeft,
                        'dots' => Heroicon::OutlinedChatBubbleOvalLeftEllipsis,
                        'talk' => Heroicon::OutlinedChatBubbleLeftRight,
                        'help' => Heroicon::OutlinedQuestionMarkCircle,
                        'sparkles' => Heroicon::OutlinedSparkles,
                    ])
                    ->hiddenButtonLabels()
                    ->inline()
                    ->default('chat')
                    ->live()
                    ->visible(fn (Get $get): bool => $get('launcher_image') === ChatLauncher::IMAGE_ICON)
                    ->columnSpanFull(),

                FileUpload::make('launcher_picture')
                    ->label('Картинка для кнопки')
                    ->avatar()
                    ->automaticallyResizeImagesToWidth('256')
                    ->automaticallyResizeImagesToHeight('256')
                    ->imageEditor()
                    ->circleCropper()
                    ->automaticallyOpenImageEditorForAspectRatio()
                    // Без SVG — по той же причине, что у аватара.
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(1024)
                    ->disk(ChatAppearance::DISK)
                    ->directory(ChatAppearance::DIRECTORY)
                    ->visibility('public')
                    ->live()
                    ->visible(fn (Get $get): bool => $get('launcher_image') === ChatLauncher::IMAGE_CUSTOM)
                    ->helperText('Только для кнопки: в подсказке и в шапке чата остаётся аватар. Без картинки — значок.')
                    ->columnSpanFull(),

                TextInput::make('launcher_status')
                    ->label('Строка под именем')
                    ->placeholder(ChatLauncher::DEFAULT_STATUS)
                    ->maxLength(ChatLauncher::STATUS_LENGTH)
                    ->live(debounce: 600)
                    ->visible(fn (Get $get): bool => $get('launcher_style') === ChatLauncher::STYLE_PILL)
                    ->helperText('Имя — из «Настроек бота».'),

                ToggleButtons::make('launcher_effect')
                    ->label('Эффект')
                    ->options(ChatLauncher::EFFECTS)
                    ->inline()
                    ->default('none')
                    ->live()
                    ->hidden($floatingRobot)
                    ->columnSpanFull()
                    ->helperText('Привлекает взгляд, пока чат свёрнут; когда покупатель открыл чат, затихает до конца визита. '
                        .'Тем, у кого в системе отключена анимация, эффект не показывается — если она отключена у вас, '
                        .'его не будет и в предпросмотре.'),

                ToggleButtons::make('launcher_dot')
                    ->label('Зелёная точка «на связи»')
                    ->options(ChatLauncher::DOTS)
                    ->inline()
                    ->default('on')
                    ->live()
                    ->columnSpanFull()
                    ->helperText('У лица в подсказке и на кнопке с картинкой или именем. Когда бот выключен, точки нет при любом выборе.'),

                ToggleButtons::make('launcher_side')
                    ->label('Угол')
                    ->options(ChatLauncher::SIDES)
                    ->icons([
                        'right' => Heroicon::OutlinedArrowDownRight,
                        'left' => Heroicon::OutlinedArrowDownLeft,
                    ])
                    ->inline()
                    ->default('right')
                    ->live()
                    ->helperText('Слева — если справа кнопка закрывает что-то нужное.'),

                Grid::make(2)
                    ->schema([
                        TextInput::make('launcher_y')
                            ->label('Отступ снизу')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(ChatLauncher::MAX_OFFSET)
                            ->placeholder((string) ChatLauncher::DEFAULT_Y)
                            ->suffix('px')
                            ->live(debounce: 600),

                        TextInput::make('launcher_x')
                            ->label('Отступ от края')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(ChatLauncher::MAX_OFFSET)
                            ->placeholder((string) ChatLauncher::DEFAULT_X)
                            ->suffix('px')
                            ->live(debounce: 600),
                    ]),

                Toggle::make('launcher_drag')
                    ->label('Покупатель может перетащить кнопку')
                    ->helperText('Мышкой или пальцем: кнопка встаёт к ближайшему краю на той высоте, где её отпустили, '
                        .'и запоминается в браузере покупателя. Удобно на телефоне, если кнопка закрыла что-то нужное.')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * «Выбрать из готовых» у аватара: картинка из набора копируется на диск
     * и встаёт в поле, как загруженная. Нет набора — нет и кнопки.
     */
    private function presetAction(): Action
    {
        return Action::make('avatarPreset')
            ->label('Выбрать из готовых')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->visible(fn (): bool => app(AvatarPresets::class)->all() !== [])
            ->modalHeading('Готовые аватары')
            ->modalDescription('Картинка встанет в поле аватара; на сайте — после сохранения.')
            ->modalSubmitActionLabel('Выбрать')
            ->modalWidth('lg')
            ->schema([
                Radio::make('preset')
                    ->hiddenLabel()
                    ->label('Аватар')
                    ->options(fn (): array => array_map(
                        static fn (string $url): HtmlString => new HtmlString(
                            '<img src="'.e($url).'" alt="" width="64" height="64" style="display:block;width:64px;height:64px;border-radius:50%;object-fit:cover">'
                        ),
                        app(AvatarPresets::class)->all(),
                    ))
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $data, Set $set): void {
                $path = app(AvatarPresets::class)->copyTo((string) ($data['preset'] ?? ''), ChatAppearance::DISK, ChatAppearance::DIRECTORY);

                if ($path !== null) {
                    $set('avatar', [$path]);
                }
            });
    }

    /**
     * Поля кнопки из состояния формы — в том виде, в каком их понимает
     * `ChatLauncher::from()`.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function launcherState(array $state): array
    {
        return [
            'style' => $state['launcher_style'] ?? null,
            'size' => $state['launcher_size'] ?? null,
            'image' => $state['launcher_image'] ?? null,
            'icon' => $state['launcher_icon'] ?? null,
            'status' => $state['launcher_status'] ?? null,
            'effect' => $state['launcher_effect'] ?? null,
            'dot' => $state['launcher_dot'] ?? null,
            'side' => $state['launcher_side'] ?? null,
            'x' => $state['launcher_x'] ?? null,
            'y' => $state['launcher_y'] ?? null,
            'drag' => (bool) ($state['launcher_drag'] ?? false),
        ];
    }

    /**
     * Строка настройки: null — удалить, и правка умолчания в коде доезжает
     * до витрины, где его не трогали. `autoload` — их читает каждая страница.
     *
     * Удаление — через модель, а не запросом: кэш настроек сбрасывает
     * событие `deleted`, а массовый `delete()` событий не шлёт.
     */
    private function persist(string $key, mixed $value, SettingType $type): void
    {
        if ($value === null) {
            Setting::query()->where('key', $key)->first()?->delete();
        } else {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
                    'type' => $type,
                    'autoload' => true,
                ],
            );
        }

        // Действует в этом же запросе: форма сразу показывает сохранённое.
        config()->set('settings.'.$key, $value);
    }

    /** Путь файла из проверенного состояния поля загрузки. */
    private static function file(mixed $state): ?string
    {
        $file = is_array($state) ? reset($state) : $state;

        return is_string($file) && $file !== '' ? $file : null;
    }

    /**
     * Картинка в предпросмотре: только что загруженная — по временной
     * ссылке Livewire, сохранённая — с публичного диска.
     */
    private function previewImageUrl(mixed $state): ?string
    {
        $file = is_array($state) ? reset($state) : $state;

        if ($file instanceof TemporaryUploadedFile) {
            try {
                return $file->isPreviewable() ? $file->temporaryUrl() : null;
            } catch (Throwable) {
                return null;
            }
        }

        return is_string($file) && $file !== '' ? Storage::disk(ChatAppearance::DISK)->url($file) : null;
    }
}
