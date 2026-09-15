<?php

namespace App\Support;

/**
 * Режим работы магазина — один на сайт и на ассистента.
 *
 * Раньше у одного факта было три дома: строка в шаблоне шапки, строка
 * в шаблоне подвала и текст страницы «О компании», и они разошлись
 * (9–18 против 9–17). Теперь источник один — настройка `company.work_schedule`,
 * её правят в админке, а шапка, подвал, блок на страницах и база знаний бота
 * только показывают её.
 *
 * Хранится структурой, а не строкой: статус «менеджер на связи» в чате
 * считает по часам, и из строки «Пн – Пт: 9:00 – 18:00» их пришлось бы
 * выковыривать обратно.
 */
final readonly class WorkSchedule
{
    public const DAY_NAMES = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    public const DAY_FULL_NAMES = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];

    /**
     * @param  array<int, array{0: string, 1: string}|null>  $days  день недели ISO-8601 (1 = понедельник)
     *                                                              → часы «HH:MM»; null — выходной
     */
    public function __construct(
        public array $days,
        public string $note = '',
    ) {}

    public static function fromConfig(): self
    {
        return self::fromArray(config('company.work_schedule'));
    }

    /**
     * Из того, что лежит в настройке. Всё непонятное — выходной, а не ошибка:
     * шапка сайта не должна падать из-за кривой строки в базе.
     */
    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $rawDays = is_array($value['days'] ?? null) ? $value['days'] : [];
        $days = [];

        foreach (array_keys(self::DAY_NAMES) as $day) {
            $days[$day] = self::hours($rawDays[$day] ?? $rawDays[(string) $day] ?? null);
        }

        $note = is_string($value['note'] ?? null) ? trim($value['note']) : '';

        return new self($days, $note);
    }

    /**
     * @return array{days: array<int, array{0: string, 1: string}|null>, note: string}
     */
    public function toArray(): array
    {
        return ['days' => $this->days, 'note' => $this->note];
    }

    public function hasOpenDays(): bool
    {
        return array_filter($this->days) !== [];
    }

    /**
     * Строки для покупателя: соседние дни с одинаковыми часами склеиваются.
     * «Пн – Пт: 9:00 – 18:00», «Сб: 10:00 – 15:00», «Вс: выходной».
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];
        $run = null;

        foreach ($this->days as $day => $hours) {
            if ($run !== null && $run['hours'] === $hours) {
                $run['to'] = $day;

                continue;
            }

            if ($run !== null) {
                $lines[] = $this->line($run);
            }

            $run = ['from' => $day, 'to' => $day, 'hours' => $hours];
        }

        if ($run !== null) {
            $lines[] = $this->line($run);
        }

        return $lines;
    }

    /** Одной строкой — для списка настроек в админке. */
    public function summary(): string
    {
        return implode(', ', $this->lines());
    }

    /**
     * @param  array{from: int, to: int, hours: array{0: string, 1: string}|null}  $run
     */
    private function line(array $run): string
    {
        $days = $run['from'] === $run['to']
            ? self::DAY_NAMES[$run['from']]
            : self::DAY_NAMES[$run['from']].' – '.self::DAY_NAMES[$run['to']];

        $hours = $run['hours'] === null
            ? 'выходной'
            : self::display($run['hours'][0]).' – '.self::display($run['hours'][1]);

        return $days.': '.$hours;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function hours(mixed $hours): ?array
    {
        if (! is_array($hours) || count($hours) < 2) {
            return null;
        }

        $from = self::time(array_values($hours)[0]);
        $to = self::time(array_values($hours)[1]);

        return $from === null || $to === null ? null : [$from, $to];
    }

    /** «9:00» и «09:00:00» (так отдаёт поле времени в админке) → «09:00». */
    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $match) !== 1) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /** «09:00» → «9:00»: так режим работы всегда писался на сайте. */
    private static function display(string $time): string
    {
        return (int) substr($time, 0, 2).substr($time, 2);
    }
}
