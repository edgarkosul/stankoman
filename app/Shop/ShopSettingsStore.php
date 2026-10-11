<?php

namespace App\Shop;

use App\Models\Setting;
use App\Support\WorkSchedule;
use Illuminate\Support\Collection;
use LogicException;
use Siteko\Chatbot\Contracts\SettingsStore;
use Siteko\Chatbot\Settings\DatabaseSettingsStore;
use Siteko\Chatbot\Settings\Keys;
use Throwable;

/**
 * Настройки сайта для пакета бота: своё — в `chatbot_settings`, общее
 * с витриной — из настроек магазина (решение Эдгара 10.10, реестр bots 10.3).
 *
 * Название, почта для покупателей, режим работы, адрес сайта — один факт
 * в одном месте: их правят в «Настройках» магазина, и шапка сайта с ботом
 * обязаны говорить одно и то же. Поэтому эти ключи пакет читает отсюда,
 * а записать их через пакет нельзя — исключение, а не тихая вторая копия.
 *
 * Читаются в момент вызова, из того же кэша `settings`, что раскладывает
 * `SettingsServiceProvider`, а не из `config()`: конфиг воркер очереди
 * собрал при старте и держит до перезапуска, а правку режима работы бот
 * обязан увидеть со следующего ответа.
 *
 * ⚠️ `site.name` уходит префиксом в каждый фрагмент базы знаний, а значит
 * в его вектор. Значение — ровно `general.shop_name` без пробелов по краям,
 * как у старого движка: иначе все векторы пересчитались бы.
 */
final class ShopSettingsStore implements SettingsStore
{
    /** Ключи пакета, которые принадлежат магазину. */
    private const SHOP_KEYS = [
        Keys::SITE_NAME,
        Keys::SITE_URL,
        Keys::SITE_HOSTS,
        Keys::PRIVACY_URL,
        Keys::CONTACT_EMAIL,
        Keys::OPERATORS_SCHEDULE,
        Keys::ESCALATION_EMAILS,
    ];

    /** Пустые значения этих настроек магазин не применяет — как `SettingsServiceProvider`. */
    private const KEEP_CONFIG_WHEN_BLANK = [
        'general.manager_emails',
        'general.filament_admin_emails',
        'company.public_email',
        'mail.from.address',
    ];

    public function __construct(private readonly DatabaseSettingsStore $own) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if (! in_array($key, self::SHOP_KEYS, true)) {
            return $this->own->get($key, $default);
        }

        $value = $this->shop($key);

        return $value === null || $value === '' || $value === [] ? $default : $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->guard($key);
        $this->own->set($key, $value);
    }

    public function forget(string $key): void
    {
        $this->guard($key);
        $this->own->forget($key);
    }

    public function all(): array
    {
        $all = $this->own->all();

        foreach (self::SHOP_KEYS as $key) {
            $all[$key] = $this->shop($key);
        }

        return array_filter($all, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function shop(string $key): mixed
    {
        return match ($key) {
            Keys::SITE_NAME => trim((string) self::setting('general.shop_name')),
            Keys::SITE_URL => rtrim(trim((string) self::setting('company.site_url')), '/'),
            // Свои хосты — те, ссылки на которые в ленте остаются ссылками.
            Keys::SITE_HOSTS => implode(',', (array) config('ai_support.chat.own_hosts', [])),
            Keys::PRIVACY_URL => route('page.show', 'privacy'),
            Keys::CONTACT_EMAIL => trim((string) self::setting('company.public_email')),
            // Пакету — только дни; примечание к режиму («в праздники…») он не читает.
            Keys::OPERATORS_SCHEDULE => WorkSchedule::fromArray(self::setting('company.work_schedule'))->days,
            // Письма об эскалации — туда же, куда заявки «перезвоните»: менеджер
            // разбирает то и другое в одном ящике. Пусто — решает EscalationTarget.
            Keys::ESCALATION_EMAILS => self::emails(self::setting('general.manager_emails')),
            default => null,
        };
    }

    private function guard(string $key): void
    {
        if (in_array($key, self::SHOP_KEYS, true)) {
            throw new LogicException("Настройку {$key} правят в «Настройках» магазина, а не в настройках бота.");
        }
    }

    /**
     * Значение настройки магазина сейчас: строка таблицы из общего кэша,
     * а нет её — значение из конфига (файлы `config/company.php` и т. п.).
     */
    private static function setting(string $key): mixed
    {
        $row = self::rows()->firstWhere('key', $key);

        if ($row instanceof Setting) {
            $value = $row->getValueForConfig();

            if (! (in_array($key, self::KEEP_CONFIG_WHEN_BLANK, true) && blank($value))) {
                return $value;
            }
        }

        $path = str_starts_with($key, 'company.') || str_starts_with($key, 'mail.') ? $key : 'settings.'.$key;

        return config($path);
    }

    /**
     * @return Collection<int, Setting>
     */
    private static function rows(): Collection
    {
        try {
            return cache()->rememberForever(Setting::CACHE_KEY, static fn () => Setting::query()
                ->where('autoload', true)
                ->get());
        } catch (Throwable) {
            return new Collection;
        }
    }

    /**
     * @return list<string>
     */
    private static function emails(mixed $value): array
    {
        $value = is_string($value) ? preg_split('/[\s,;]+/', $value) : $value;

        return collect(is_array($value) ? $value : [])
            ->map(static fn (mixed $email): string => mb_strtolower(trim((string) $email)))
            ->filter(static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
