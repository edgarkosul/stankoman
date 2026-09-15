<?php

namespace App\Services\Chat;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Кому виден виджет чата, пока он в предпросмотре.
 *
 * Нужен, чтобы код можно было выкатить на настоящий сайт раньше, чем
 * виджет увидят покупатели. Отдельная копия магазина решала бы ту же
 * задачу дороже и хуже: вторая база с каталогом, свой воркер и риск,
 * что поисковики проиндексируют дубль витрины. А заказчику нужен именно
 * настоящий сайт с настоящими товарами — на копии с устаревшим каталогом
 * бот отвечает не то, что покажет покупателю.
 *
 * ДОСТУП ДАЁТ ССЫЛКА, а не пароль: заказчик открывает
 * `https://intertooler.ru/?bot=<ключ>` один раз, дальше его опознаёт кука
 * на год. Пароль здесь был бы лишним экраном ради того, что и так
 * не тайна: виджет чата не показывает чужих данных.
 *
 * Сотрудники магазина видят виджет всегда: обучать администратора,
 * выдавая ему сначала секретную ссылку, — способ гарантированно
 * потерять эту ссылку.
 */
final class ChatPreviewGate
{
    public const COOKIE = 'intertooler_chat_preview';

    /** Год: предпросмотр длится недели, а не часы, и продлевать его руками незачем. */
    private const COOKIE_MINUTES = 525_600;

    /**
     * @param  (Closure(): bool)|null  $isStaff  сотрудник ли текущий посетитель. Кто
     *                                           такой сотрудник, знает магазин, а не чат
     */
    public function __construct(
        private readonly bool $previewOnly,
        private readonly string $key,
        private readonly ?Closure $isStaff = null,
    ) {}

    /**
     * Показывать ли виджет этому посетителю.
     *
     * `$isStaff` передаётся снаружи: так решение остаётся проверяемым
     * без базы и без сессии.
     */
    public function allows(?Request $request = null, bool $isStaff = false): bool
    {
        if (! $this->previewOnly) {
            return true;
        }

        if ($isStaff) {
            return true;
        }

        // Пустой ключ при включённом предпросмотре — это не «пускать всех»,
        // а «не пускать никого»: иначе забытая настройка молча открывала бы
        // виджет всему свету.
        if ($this->key === '') {
            return false;
        }

        $request ??= request();

        return $this->matches((string) $request->query('bot'))
            || $this->matches((string) $request->cookie(self::COOKIE));
    }

    /**
     * Ключ пришёл ссылкой — запоминаем его кукой, чтобы дальше человек
     * ходил по сайту обычными адресами.
     */
    public function rememberIfInvited(?Request $request = null): void
    {
        $request ??= request();

        if (! $this->previewOnly || $this->key === '') {
            return;
        }

        if ($this->matches((string) $request->query('bot'))) {
            Cookie::queue(Cookie::make(
                name: self::COOKIE,
                value: $this->key,
                minutes: self::COOKIE_MINUTES,
                httpOnly: true,
                sameSite: 'lax',
            ));
        }
    }

    /** Видно ли виджет прямо сейчас — единственное, что нужно макету. */
    public function visibleNow(): bool
    {
        // Без предпросмотра сотрудника не спрашиваем вовсе: на каждой
        // странице витрины это лишнее обращение к пользователю сессии.
        if (! $this->previewOnly) {
            return true;
        }

        $this->rememberIfInvited();

        return $this->allows(null, $this->isStaff !== null && ($this->isStaff)());
    }

    public function previewOnly(): bool
    {
        return $this->previewOnly;
    }

    /** Сравнение постоянного времени: ключ короткий, перебор дешёвый. */
    private function matches(string $candidate): bool
    {
        return $candidate !== '' && hash_equals($this->key, $candidate);
    }
}
