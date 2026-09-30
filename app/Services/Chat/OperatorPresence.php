<?php

namespace App\Services\Chat;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Есть ли сейчас за столом живой человек.
 *
 * Присутствие — сигнал, а не расписание, и это принципиально. Расписание
 * знает, что по вторникам с девяти работают; оно не знает, что сегодня
 * менеджер уехал к поставщику, а в субботу сам сел разгребать почту.
 * Обещание живого оператора, которое магазин не сдержит, хуже честного
 * «оставьте почту»: посетитель ждёт ответа в чате и уходит ни с чем.
 *
 * Поэтому три источника с жёстким приоритетом:
 *
 *   1) РУЧНОЙ ПЕРЕКЛЮЧАТЕЛЬ в топбаре админки. Перебивает всё в обе
 *      стороны. Живёт не дольше override_ttl: переключатель без срока
 *      годности опаснее пользы, потому что забывают именно его.
 *   2) АКТИВНОСТЬ В АДМИНКЕ. Она и так опрашивает уведомления, значит
 *      открытая вкладка — это и есть человек за столом.
 *   3) РАСПИСАНИЕ как база, когда первых двух сигналов нет. Это режим
 *      работы магазина из «Настроек» (`company.work_schedule`) — тот же,
 *      что в шапке и подвале сайта.
 *
 * Класс намеренно не знает ни про Livewire, ни про Filament: всё состояние
 * лежит в кэше и приходит в конструктор, поэтому поведение проверяется
 * тестом без базы.
 */
final class OperatorPresence
{
    public const SOURCE_OVERRIDE = 'override';

    public const SOURCE_ACTIVITY = 'activity';

    public const SOURCE_SCHEDULE = 'schedule';

    private const OVERRIDE_KEY = 'chat:operator:override';

    private const ACTIVITY_KEY = 'chat:operator:activity';

    private const DAY_NAMES = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    /**
     * @param  array<int|string, array{0: string, 1: string}|null>  $schedule  часы работы по дням ISO-8601
     */
    public function __construct(
        private readonly array $schedule,
        private readonly string $timezone,
        private readonly int $activityWindowMinutes,
        private readonly int $overrideTtlMinutes,
    ) {}

    public function isOnline(): bool
    {
        return $this->resolve()['online'];
    }

    /** Какой из трёх источников дал ответ — для админки и для логов. */
    public function source(): string
    {
        return $this->resolve()['source'];
    }

    /**
     * @return array{online: bool, source: string, until: ?CarbonImmutable}
     */
    public function resolve(): array
    {
        $override = $this->override();

        if ($override !== null) {
            return [
                'online' => $override['online'],
                'source' => self::SOURCE_OVERRIDE,
                'until' => $override['until'],
            ];
        }

        $activeSince = $this->lastActivityAt();

        if ($activeSince !== null) {
            return [
                'online' => true,
                'source' => self::SOURCE_ACTIVITY,
                'until' => $activeSince->addMinutes($this->activityWindowMinutes),
            ];
        }

        return [
            'online' => $this->isWithinSchedule(),
            'source' => self::SOURCE_SCHEDULE,
            'until' => null,
        ];
    }

    /**
     * Ручной переключатель. TTL обязателен: смена длиннее восьми часов —
     * повод нажать кнопку ещё раз, а не повод забыть о ней навсегда.
     */
    public function setOverride(bool $online, ?int $userId = null): void
    {
        Cache::put(self::OVERRIDE_KEY, [
            'online' => $online,
            'by' => $userId,
            'until' => CarbonImmutable::now($this->timezone)
                ->addMinutes($this->overrideTtlMinutes)
                ->getTimestamp(),
        ], $this->overrideTtlMinutes * 60);
    }

    public function clearOverride(): void
    {
        Cache::forget(self::OVERRIDE_KEY);
    }

    /**
     * @return array{online: bool, by: ?int, until: CarbonImmutable}|null
     */
    public function override(): ?array
    {
        $stored = Cache::get(self::OVERRIDE_KEY);

        if (! is_array($stored) || ! array_key_exists('online', $stored)) {
            return null;
        }

        $until = CarbonImmutable::createFromTimestamp((int) ($stored['until'] ?? 0), $this->timezone);

        // Страховка на случай, если кэш переживёт свой TTL (например,
        // после смены драйвера): срок жизни записан ещё и внутри неё.
        if ($until->isPast()) {
            $this->clearOverride();

            return null;
        }

        return [
            'online' => (bool) $stored['online'],
            'by' => $stored['by'] === null ? null : (int) $stored['by'],
            'until' => $until,
        ];
    }

    /**
     * Кто-то из сотрудников только что что-то делал в админке.
     *
     * Одна метка на всех, а не по пользователю: вопрос, на который
     * отвечает присутствие, — «ответит ли кто-нибудь», а не «кто именно».
     */
    public function touchActivity(): void
    {
        Cache::put(
            self::ACTIVITY_KEY,
            CarbonImmutable::now($this->timezone)->getTimestamp(),
            $this->activityWindowMinutes * 60,
        );
    }

    /** Последняя активность в админке, если она укладывается в окно. */
    public function lastActivityAt(): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::ACTIVITY_KEY);

        if (! is_numeric($timestamp)) {
            return null;
        }

        $at = CarbonImmutable::createFromTimestamp((int) $timestamp, $this->timezone);

        return $at->addMinutes($this->activityWindowMinutes)->greaterThan(CarbonImmutable::now($this->timezone))
            ? $at
            : null;
    }

    public function isWithinSchedule(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now($this->timezone);
        $window = $this->windowFor($at);

        if ($window === null) {
            return false;
        }

        return $at->betweenIncluded($window[0], $window[1]);
    }

    /**
     * Начало ближайшей смены. Нужно уведомлениям: пуш среди ночи никого
     * не разбудит с пользой — ответить всё равно некому.
     */
    public function nextShiftStart(?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now($this->timezone);

        for ($offset = 0; $offset <= 14; $offset++) {
            $day = $from->addDays($offset);
            $window = $this->windowFor($day);

            if ($window === null) {
                continue;
            }

            if ($offset === 0 && $from->betweenIncluded($window[0], $window[1])) {
                return $from;
            }

            if ($window[0]->greaterThan($from)) {
                return $window[0];
            }
        }

        // Расписание пустое целиком — откладывать некуда, шлём сразу.
        return $from;
    }

    /** «Пн–Пт 09:00–18:00» — для подписи в виджете и в промпте. */
    public function scheduleSummary(): string
    {
        $groups = [];

        foreach (self::DAY_NAMES as $iso => $name) {
            $hours = $this->hoursFor($iso);

            if ($hours === null) {
                continue;
            }

            $groups[$hours[0].'–'.$hours[1]][] = $iso;
        }

        $parts = [];

        foreach ($groups as $hours => $days) {
            $label = count($days) > 1 && $days === range($days[0], $days[count($days) - 1])
                ? self::DAY_NAMES[$days[0]].'–'.self::DAY_NAMES[$days[count($days) - 1]]
                : implode(', ', array_map(static fn (int $iso): string => self::DAY_NAMES[$iso], $days));

            $parts[] = $label.' '.$hours;
        }

        return $parts === [] ? 'по договорённости' : implode(', ', $parts);
    }

    /**
     * Границы рабочего дня для конкретной даты.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function windowFor(CarbonImmutable $day): ?array
    {
        $hours = $this->hoursFor((int) $day->isoWeekday());

        if ($hours === null) {
            return null;
        }

        [$fromHour, $fromMinute] = $this->parseTime($hours[0]);
        [$toHour, $toMinute] = $this->parseTime($hours[1]);

        return [
            $day->setTime($fromHour, $fromMinute),
            $day->setTime($toHour, $toMinute),
        ];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function hoursFor(int $isoWeekday): ?array
    {
        $hours = $this->schedule[$isoWeekday] ?? $this->schedule[(string) $isoWeekday] ?? null;

        if (! is_array($hours) || count($hours) < 2) {
            return null;
        }

        $from = (string) ($hours[0] ?? '');
        $to = (string) ($hours[1] ?? '');

        return $from === '' || $to === '' ? null : [$from, $to];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseTime(string $time): array
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '0');

        return [max(0, min(23, (int) $hour)), max(0, min(59, (int) $minute))];
    }
}
