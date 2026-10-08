<?php

namespace App\Services\Chat;

/**
 * Кнопка чата на витрине: какой она формы, что на ней, чем она привлекает
 * взгляд и в каком углу стоит.
 *
 * Перенесено из bots (`Siteko\Chatbot\Widget\Launcher`, 06.10.2026) вместе
 * с белыми списками: из значений собираются классы в разметке витрины,
 * поэтому любое неизвестное слово превращается в умолчание, а не доезжает
 * до HTML. Отличие от донора одно — четвёртое «лицо» кнопки, робот.
 *
 * Умолчание — ровно та кнопка, что была до настроек: анимированный робот
 * без диска в правом нижнем углу, 16 px от краёв. Пока владелец ничего
 * не выбрал, витрина не меняется. Настройки пришли из kratonshop 08.10.2026,
 * где за неделю боту не задали ни одного вопроса; прежний выключатель
 * робота («Лицо бота») они заменили — см. `ChatAppearance::launcher()`.
 */
final class ChatLauncher
{
    public const STYLE_CIRCLE = 'circle';

    /** «Пилюля»: картинка, имя бота и строка под ним. На телефоне — кружок. */
    public const STYLE_PILL = 'pill';

    public const STYLES = [
        self::STYLE_CIRCLE => 'Кружок',
        self::STYLE_PILL => 'С именем',
    ];

    /**
     * Анимированный робот. На кружке — прежняя кнопка без диска (размер
     * и эффект к нему не относятся: он движется сам), в пилюле — тот же
     * робот, только спокойный и в кружке рядом с именем.
     */
    public const IMAGE_ROBOT = 'robot';

    public const IMAGE_ICON = 'icon';

    /** Тот же аватар, что в подсказке и в шапке чата. */
    public const IMAGE_AVATAR = 'avatar';

    /** Отдельная картинка только для кнопки. */
    public const IMAGE_CUSTOM = 'custom';

    public const IMAGES = [
        self::IMAGE_ROBOT => 'Робот',
        self::IMAGE_ICON => 'Значок',
        self::IMAGE_AVATAR => 'Аватар бота',
        self::IMAGE_CUSTOM => 'Своя картинка',
    ];

    public const ICONS = [
        'chat' => 'Облачко',
        'dots' => 'Облачко с точками',
        'talk' => 'Переписка',
        'help' => 'Вопрос',
        'sparkles' => 'Искры',
    ];

    /**
     * Контуры значков (viewBox 0 0 24 24, обводка). «Облачко» — то самое,
     * что стояло на кнопке до приезда робота; остальные — Heroicons, как
     * в bots.
     */
    public const ICON_PATHS = [
        'chat' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'dots' => '<path d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>',
        'talk' => '<path d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>',
        'help' => '<path d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>',
        'sparkles' => '<path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/>',
    ];

    public const EFFECTS = [
        'none' => 'Без эффекта',
        'rings' => 'Кольца',
        'shine' => 'Блик',
        'nudge' => 'Покачивание',
    ];

    /** Зелёная точка «на связи» у лица: на подсказке и на кнопке с картинкой. */
    public const DOTS = [
        'off' => 'Нет',
        'on' => 'Горит',
        'pulse' => 'Пульсирует',
    ];

    /** Размер кружка: 48, 56 и 64 пикселя. Робот на кружке — всегда 72. */
    public const SIZES = [
        's' => 'S',
        'm' => 'M',
        'l' => 'L',
    ];

    public const SIZE_PX = ['s' => 48, 'm' => 56, 'l' => 64];

    /** Коробка робота на кружке — прежние `h-18 w-18`. */
    public const ROBOT_PX = 72;

    public const SIDES = [
        'right' => 'Справа',
        'left' => 'Слева',
    ];

    /** Вторая строка пилюли — пара слов под именем, не фраза. */
    public const STATUS_LENGTH = 24;

    public const DEFAULT_STATUS = 'Онлайн';

    /**
     * Отступ от края окна. Двести пикселей хватает, чтобы подняться над
     * cookie-плашкой; дальше кнопка уже не в углу.
     */
    public const MAX_OFFSET = 200;

    /** Прежние `right-4`. */
    public const DEFAULT_X = 16;

    /** Прежние `bottom-4`. */
    public const DEFAULT_Y = 16;

    public function __construct(
        public readonly string $style = self::STYLE_CIRCLE,
        public readonly string $image = self::IMAGE_ROBOT,
        public readonly string $icon = 'chat',
        public readonly string $status = self::DEFAULT_STATUS,
        public readonly string $effect = 'none',
        public readonly string $dot = 'on',
        public readonly string $size = 'm',
        public readonly string $side = 'right',
        public readonly int $x = self::DEFAULT_X,
        public readonly int $y = self::DEFAULT_Y,
        /**
         * Посетитель может перетащить кнопку к другому краю и на другую
         * высоту — например, на телефоне, где она закрыла «В корзину».
         * Положение помнит браузер посетителя.
         */
        public readonly bool $drag = false,
    ) {}

    /** Кнопка из того, что пришло из админки или из базы; мусор — умолчания. */
    public static function from(mixed $raw): self
    {
        $raw = is_array($raw) ? $raw : [];
        $default = new self;

        return new self(
            style: self::pick($raw['style'] ?? null, self::STYLES, $default->style),
            image: self::pick($raw['image'] ?? null, self::IMAGES, $default->image),
            icon: self::pick($raw['icon'] ?? null, self::ICONS, $default->icon),
            status: self::status($raw['status'] ?? null),
            effect: self::pick($raw['effect'] ?? null, self::EFFECTS, $default->effect),
            dot: self::pick($raw['dot'] ?? null, self::DOTS, $default->dot),
            size: self::pick($raw['size'] ?? null, self::SIZES, $default->size),
            side: self::pick($raw['side'] ?? null, self::SIDES, $default->side),
            x: self::offset($raw['x'] ?? null, self::DEFAULT_X),
            y: self::offset($raw['y'] ?? null, self::DEFAULT_Y),
            drag: ($raw['drag'] ?? false) === true,
        );
    }

    /**
     * Для хранилища — только то, что отличается от умолчания: тогда правка
     * умолчания в коде доезжает до витрины, где его не трогали.
     *
     * @return array<string, string|int|bool>
     */
    public function toArray(): array
    {
        $default = new self;

        return array_filter(
            get_object_vars($this),
            static fn (string|int|bool $value, string $name): bool => $value !== $default->{$name},
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function isDefault(): bool
    {
        return $this->toArray() === [];
    }

    /** Прежний робот без диска — кружок с роботом. */
    public function floatingRobot(): bool
    {
        return $this->image === self::IMAGE_ROBOT && $this->style === self::STYLE_CIRCLE;
    }

    /** Сторона коробки кнопки в пикселях (у пилюли — высота). */
    public function heightPx(): int
    {
        return $this->floatingRobot() ? self::ROBOT_PX : self::SIZE_PX[$this->size];
    }

    /**
     * Классы обёртки кнопки. Их же ставит предпросмотр в админке, поэтому
     * собираются они здесь, а не в шаблоне.
     *
     * `$picture` — на кнопке картинка (аватар, своя, робот в пилюле), а не
     * значок: тогда у неё и точка «на связи».
     */
    public function classes(bool $picture): string
    {
        $robot = $this->floatingRobot();

        return implode(' ', array_filter([
            'chat-launcher',
            $robot ? 'cl-robot' : null,
            $this->style === self::STYLE_PILL ? 'cl-pill' : null,
            $picture && ! $robot ? 'cl-picture' : null,
            ! $robot && $this->size !== 'm' ? 'cl-size-'.$this->size : null,
            $this->side === 'left' ? 'cl-left' : null,
            ! $robot && $this->effect !== 'none' ? 'cl-fx-'.$this->effect : null,
            match ($this->dot) {
                'off' => 'cl-no-dot',
                'pulse' => 'cl-dot-pulse',
                default => null,
            },
        ]));
    }

    public function iconPath(): string
    {
        return self::ICON_PATHS[$this->icon] ?? self::ICON_PATHS['chat'];
    }

    /** @param  array<string, string>  $options */
    private static function pick(mixed $value, array $options, string $default): string
    {
        return is_string($value) && array_key_exists($value, $options) ? $value : $default;
    }

    private static function status(mixed $value): string
    {
        $text = is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '') : '';
        $text = rtrim(mb_substr($text, 0, self::STATUS_LENGTH));

        return $text === '' ? self::DEFAULT_STATUS : $text;
    }

    private static function offset(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(0, min(self::MAX_OFFSET, (int) $value)) : $default;
    }
}
