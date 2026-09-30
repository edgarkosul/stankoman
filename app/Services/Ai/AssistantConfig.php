<?php

namespace App\Services\Ai;

/**
 * Настраиваемый слой ассистента: то, что владелец правит из админки.
 *
 * Промпт разделён надвое не ради красоты. Отдать админу весь текст нельзя —
 * он снесёт гарды и не заметит, потому что бот после этого не сломается,
 * а начнёт тихо отвечать на посторонние темы. Не отдать ничего — и каждая
 * мелочь вроде «доставка по городу бесплатно от 50 000» идёт через
 * разработчика, то есть не делается никогда.
 *
 * Значения живут в общей таблице `settings` под префиксом `assistant.`,
 * откуда `SettingsServiceProvider` раскладывает их в `config('settings.*')`
 * на старте приложения. Читаем из конфига, а не из базы: чтение идёт на
 * каждый ответ бота, в том числе внутри воркера, а запись — раз в месяц.
 *
 * Здесь только чтение. Пишет страница «ИИ бот» → «Настройки бота»: модель
 * `Setting` — магазинная, а сервисы ассистента моделей магазина не знают.
 * Пока форму ни разу не сохраняли, строк в `settings` нет, и всё берётся
 * из значений по умолчанию ниже.
 */
final class AssistantConfig
{
    /** Префикс ключей в таблице настроек. */
    public const PREFIX = 'assistant.';

    /** Сколько правил магазина имеет смысл держать в промпте. */
    public const MAX_RULES = 30;

    public function __construct(
        /** Название магазина — для имени бота по умолчанию. */
        private readonly string $shopName,
    ) {}

    /**
     * Работает ли бот прямо сейчас.
     *
     * Два выключателя, и они НЕ равноправны. `AI_AGENT_ENABLED=false`
     * в `.env` — аварийный рубильник разработчика: он перебивает админку
     * и переживает любое нажатие в интерфейсе. Настройка в админке —
     * рабочий выключатель владельца магазина.
     *
     * Порядок именно такой, потому что авария случается тогда, когда
     * админку открыть некому: если бот начал грубить или шлюз выставил
     * счёт, выключать его надо строкой в конфиге, а не кнопкой.
     */
    public function enabled(): bool
    {
        if (! (bool) config('ai_support.agent.enabled', true)) {
            return false;
        }

        $flag = config('settings.'.self::PREFIX.'enabled');

        // Настройки ещё нет — бот включён. Иначе первый же выкат погасил бы
        // виджет молча, до того как владелец откроет страницу настроек.
        return $flag === null || (bool) $flag;
    }

    /**
     * Выключен именно в админке, а не аварийным рубильником.
     *
     * Доктор и страница настроек называют виновника: выключатель в админке
     * владелец снимет сам, а строку в `.env` он не найдёт никогда.
     */
    public function disabledByAdmin(): bool
    {
        if (! (bool) config('ai_support.agent.enabled', true)) {
            return false;
        }

        $flag = config('settings.'.self::PREFIX.'enabled');

        return $flag !== null && ! (bool) $flag;
    }

    /**
     * Как зовут бота.
     *
     * Имя выходит в четыре места сразу (кнопка чата, подсказка у неё,
     * шапка панели и ответ самого бота на вопрос «кто ты»), и разойтись
     * они не имеют права.
     */
    public function botName(): string
    {
        $name = $this->text('bot_name');

        return $name !== '' ? $name : $this->defaultBotName();
    }

    public function defaultBotName(): string
    {
        return trim('Консультант '.$this->shopName);
    }

    /**
     * Строка в подсказке у кнопки чата — то, чем бот зовёт начать разговор.
     *
     * Отдельная от приветствия: приветствие — три предложения для пустого
     * чата, а в подсказке шириной в треть экрана это стена текста, которую
     * не дочитывают.
     */
    public function invite(): string
    {
        $invite = $this->text('invite');

        return $invite !== '' ? $invite : self::defaultInvite();
    }

    public static function defaultInvite(): string
    {
        // Без «отвечу лично» и «я на связи»: робот на аватаре и так честен,
        // а обещание живого собеседника ему противоречит.
        return 'Подскажу наличие, цену и условия доставки — спрашивайте.';
    }

    /** Первая фраза в пустом чате. Её видит покупатель до всякой модели. */
    public function greeting(): string
    {
        $greeting = $this->text('greeting');

        return $greeting !== '' ? $greeting : self::defaultGreeting();
    }

    public static function defaultGreeting(): string
    {
        /*
         * Возврата в перечне нет, хотя у донора он был: статьи о возврате
         * в базе знаний пока нет, и обещать ответ на него значило бы звать
         * вопрос, на который бот честно ответит «передам менеджеру».
         */
        return 'Здравствуйте! Отвечу на вопросы об оформлении заказа, оплате, доставке '
            .'и гарантии, подскажу наличие и цену товара. '
            .'Если не справлюсь — передам вопрос менеджеру.';
    }

    /**
     * Редактируемый слой для `SystemPromptBuilder`.
     *
     * Списки склеиваются в строки здесь: в базе они лежат списком, потому
     * что так их правят, а промпту нужен текст.
     *
     * @return array<string, string>
     */
    public function promptSettings(): array
    {
        return array_filter([
            'bot_name' => $this->text('bot_name'),
            'about' => $this->text('about'),
            'rules' => $this->bulletList('rules'),
            'forbidden_topics' => $this->bulletList('forbidden_topics'),
            'refusal' => $this->text('refusal'),
            'escalation' => $this->text('escalation'),
        ], static fn (string $value): bool => $value !== '');
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = config('settings.'.self::PREFIX.$key);

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            $text = is_string($item) ? trim($item) : '';

            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_slice($items, 0, self::MAX_RULES);
    }

    public function text(string $key): string
    {
        $value = config('settings.'.self::PREFIX.$key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function bulletList(string $key): string
    {
        $items = $this->list($key);

        return $items === [] ? '' : implode("\n", array_map(
            static fn (string $item): string => '- '.$item,
            $items,
        ));
    }
}
