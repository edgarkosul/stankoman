<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Настройки бота переезжают из `settings` магазина в `chatbot_settings`
 * пакета (реестр bots 10.3.4): их читает пакет через свой SettingsStore.
 *
 * Отдельной миграцией, а не в принимающей: таблицу `chatbot_settings`
 * создаёт пакетная миграция от 19.09, то есть ПОСЛЕ принимающей.
 *
 * Что переезжает:
 *  - тексты и списки «Настроек бота» — под теми же ключами `assistant.*`;
 *  - аватар — в `widget.avatar`: его показывает шапка панели пакета;
 *  - цвет панели — фирменный зелёный витрины (`--color-brand-green`
 *    в app.css): у пакета по умолчанию синий.
 *
 * Что остаётся в `settings`: лаунчер (`assistant.launcher`,
 * `assistant.launcher_picture`, старый `assistant.mascot`) и отметка
 * `assistant.guide_read_at`. Лаунчер — витрина магазина, а не пакета:
 * у пакетного лаунчера нет робота, и его умолчания другие.
 *
 * Тексты, которых у владельца нет, пишутся явно, где умолчание пакета
 * другое: приглашение и приветствие у пакета — для любого сайта,
 * у магазина — про наличие, цену и доставку.
 *
 * Особенности магазина, которые старый движок держал в коде промпта
 * (чем торгует, кнопка звонка на карточке, чужие цены, корзина, расчёт
 * доставки), становятся видимыми и правимыми: «О магазине» и правила.
 * Остальное из того промпта пакет говорит сам (CatalogPrompt, 10.3.1).
 */
return new class extends Migration
{
    private const SITE_ID = 1;

    /** Ключи «Настроек бота»: в обоих местах имя одно. */
    private const ASSISTANT_KEYS = [
        'assistant.enabled' => 'bool',
        'assistant.bot_name' => 'string',
        'assistant.bot_gender' => 'string',
        'assistant.about' => 'string',
        'assistant.rules' => 'json',
        'assistant.forbidden_topics' => 'json',
        'assistant.invite' => 'string',
        'assistant.greeting' => 'string',
        'assistant.refusal' => 'string',
        'assistant.escalation' => 'string',
    ];

    private const AVATAR_FROM = 'assistant.avatar';

    private const AVATAR_TO = 'widget.avatar';

    private const COLOR = 'widget.color';

    /** `--color-brand-green` из resources/css/app.css. */
    private const BRAND_GREEN = '#0f6a24';

    private const DEFAULT_INVITE = 'Подскажу наличие, цену и условия доставки — спрашивайте.';

    private const DEFAULT_GREETING = 'Здравствуйте! Отвечу на вопросы об оформлении заказа, оплате, доставке '
        .'и гарантии, подскажу наличие и цену товара. Если не справлюсь — передам вопрос менеджеру.';

    private const SHOP_ABOUT = 'Интернет-магазин промышленного оборудования: станки по металлу и дереву, '
        .'компрессоры, сварочное и гидравлическое оборудование, уборочная техника, инструмент и оснастка. '
        .'Работает с физическими лицами и с организациями.';

    private const CALL_BUTTON_RULE = 'На карточке каждого товара есть кнопка «Заказать звонок менеджера». '
        .'Попросили позвонить — телефон оставляют в форме «Оставить контакты менеджеру» или этой кнопкой '
        .'на карточке товара. Сам звонок не предлагай.';

    /** @var list<string> */
    private const SHOP_RULES = [
        'Покупатель сравнивает нашу цену с чужой — сначала сравни числа. Цена конкурента выше нашей — так и скажи: '
            .'у нас дешевле. Ниже — не спорь, но и скидку сам не обещай: цену по конкретной сделке решает менеджер, '
            .'предложи оставить почту. Есть скидка для зарегистрированных — скажи и о ней.',
        'Корзину покупатель наполняет сам — кнопкой на странице товара. Не говори «товары уже в корзине».',
        'Срок и стоимость доставки конкретного заказа не называй даже примерно: их считает менеджер по весу, объёму '
            .'и адресу. Общие условия доставки из базы знаний пересказывать можно.',
    ];

    public function up(): void
    {
        $rows = DB::table('settings')
            ->whereIn('key', [...array_keys(self::ASSISTANT_KEYS), self::AVATAR_FROM])
            ->pluck('value', 'key');

        $values = [];

        foreach (self::ASSISTANT_KEYS as $key => $type) {
            $value = $this->decode($rows[$key] ?? null, $type);

            if ($value !== null && $value !== '' && $value !== []) {
                $values[$key] = $value;
            }
        }

        $values['assistant.invite'] ??= self::DEFAULT_INVITE;
        $values['assistant.greeting'] ??= self::DEFAULT_GREETING;

        $about = (string) ($values['assistant.about'] ?? '');

        if (! str_contains($about, 'промышленного оборудования')) {
            $values['assistant.about'] = trim(self::SHOP_ABOUT."\n\n".$about);
        }

        $values['assistant.rules'] = $this->withShopRules((array) ($values['assistant.rules'] ?? []));

        if (($avatar = trim((string) ($rows[self::AVATAR_FROM] ?? ''))) !== '') {
            $values[self::AVATAR_TO] = $avatar;
        }

        $colorSet = DB::table('chatbot_settings')->where('site_id', self::SITE_ID)->where('key', self::COLOR)->exists();

        if (! $colorSet) {
            $values[self::COLOR] = self::BRAND_GREEN;
        }

        foreach ($values as $key => $value) {
            DB::table('chatbot_settings')->updateOrInsert(
                ['site_id' => self::SITE_ID, 'key' => $key],
                ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
            );
        }

        DB::table('settings')->whereIn('key', [...array_keys(self::ASSISTANT_KEYS), self::AVATAR_FROM])->delete();

        Setting::flushCache();
    }

    public function down(): void
    {
        $stored = DB::table('chatbot_settings')
            ->where('site_id', self::SITE_ID)
            ->whereIn('key', [...array_keys(self::ASSISTANT_KEYS), self::AVATAR_TO])
            ->pluck('value', 'key')
            ->map(fn (string $value): mixed => json_decode($value, true));

        foreach (self::ASSISTANT_KEYS as $key => $type) {
            if (! $stored->has($key)) {
                continue;
            }

            $value = $stored[$key];

            $this->restore($key, match ($type) {
                'bool' => $value ? '1' : '0',
                'json' => json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE),
                default => (string) $value,
            }, $type);
        }

        if ($stored->has(self::AVATAR_TO)) {
            $this->restore(self::AVATAR_FROM, (string) $stored[self::AVATAR_TO], 'string');
        }

        DB::table('chatbot_settings')
            ->where('site_id', self::SITE_ID)
            ->whereIn('key', [...array_keys(self::ASSISTANT_KEYS), self::AVATAR_TO, self::COLOR])
            ->delete();

        Setting::flushCache();
    }

    private function decode(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOL),
            'json' => array_values(array_filter(
                array_map(fn (mixed $item): string => trim((string) $item), (array) (json_decode($raw, true) ?? [])),
                fn (string $item): bool => $item !== '',
            )),
            default => trim($raw),
        };
    }

    /**
     * Правила магазина — в конец списка владельца и без повторов: миграцию
     * могут прогнать на базе, где их уже переносили руками.
     *
     * Кнопка звонка — только если она сейчас на карточке (настройка
     * `product.show_callback_button`): выключат её позже — правило надо
     * будет убрать в «Настройках бота», это написано в журнале перевода.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    private function withShopRules(array $rules): array
    {
        $callButton = DB::table('settings')->where('key', 'product.show_callback_button')->value('value');
        $shopRules = filter_var($callButton ?? '1', FILTER_VALIDATE_BOOL)
            ? [self::CALL_BUTTON_RULE, ...self::SHOP_RULES]
            : self::SHOP_RULES;

        foreach ($shopRules as $rule) {
            if (! in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return array_values($rules);
    }

    private function restore(string $key, string $value, string $type): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'type' => $type, 'autoload' => true, 'updated_at' => now(), 'created_at' => now()],
        );
    }
};
