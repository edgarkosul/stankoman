<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Jenssegers\Agent\Agent;

/**
 * Все потолки чата в одном месте.
 *
 * Собраны в класс не ради порядка, а потому что порознь их невозможно
 * проверить: половина жила бы в Livewire-компоненте, где тест потребовал
 * бы браузера, базы и очереди. Здесь — ни одного запроса к базе и ни одного
 * вызова наружу, только кэш и текст сообщения.
 *
 * ГРАНИЦА ОТВЕТСТВЕННОСТИ. Первым слоем стоит nginx (`limit_req` на
 * `/livewire/update`, см. `scripts/deploy/nginx/`), и он не украшение: пока
 * запрос не дошёл до PHP, потолки ниже не сработают вовсе — воркеры FPM уже
 * заняты. Здесь то, чего nginx знать не может: чей это разговор, о чём он
 * и не кончились ли у магазина деньги.
 *
 * ПОРЯДОК ПРОВЕРОК ВАЖЕН и выведен из цены ошибки, а не из удобства:
 *
 *   1) робот               — молча, до всего остального;
 *   2) длина               — не стоит ничего и отсеивает случайный Enter;
 *   3) кулдаун и счётчики  — дешёвые, кэш;
 *   4) дневной бюджет      — ПОСЛЕДНИМ, потому что его исход дорогой:
 *      сообщение в ленте плюс уведомление менеджеру. Проверяй мы его
 *      раньше, флудер поднимал бы менеджера на каждом сообщении.
 *
 * Счётчики поднимаются только у ПРИНЯТОГО сообщения (`remember()`).
 * Иначе человек, промахнувшийся длиной три раза подряд, платил бы
 * за это своей же квотой на день.
 */
final class ChatAbuseGuard
{
    /**
     * Кликов по «помог ответ?» в минуту на разговор. Не настраивается:
     * это не рычаг владельца магазина, а защита от заклинившей мыши.
     */
    private const RATINGS_PER_MINUTE = 30;

    public function __construct(
        private readonly int $minLength,
        private readonly int $maxLength,
        private readonly int $cooldownSeconds,
        private readonly int $perConversationHour,
        private readonly int $perConversationDay,
        private readonly int $perIpDay,
        private readonly int $newConversationsPerIpDay,
        private readonly int $dailyMessages,
        private readonly int $dailyTokens,
        private readonly bool $captchaEnabled = false,
        /*
         * Часовой пояс, в котором считаются сутки дневного бюджета.
         *
         * Отдельным параметром, а не `now()`, потому что `app.timezone`
         * здесь UTC (у донора — Europe/Moscow): взяли бы её — и дневной
         * потолок обнулялся бы в три часа ночи по Москве, чего владельцу
         * магазина объяснить нечем. Пояс тот же, что у режима работы.
         */
        private readonly string $dayTimezone = 'Europe/Moscow',
    ) {}

    /**
     * Нужна ли проверка на робота прямо сейчас.
     *
     * Спрашиваем ТОЛЬКО у того, у кого разговора ещё нет; закрытый диалог
     * считается за новый. Причина не в экономии запросов к сервису, а в
     * задержке: проверка исполняется в браузере на каждый вызов, и повесить
     * её на каждую реплику — это добавить полсекунды к каждому вопросу ради
     * проверки, которую посетитель уже прошёл. Дальше его удостоверяет
     * кука разговора, а её же стережёт всё остальное в этом классе.
     *
     * Включена ли капча вообще, класс не решает: ответ приходит снаружи
     * от CaptchaManager. Здесь только правило «первое сообщение» — оно
     * про устройство чата, а не про капчу.
     */
    public function needsCaptcha(?ChatConversation $conversation): bool
    {
        if (! $this->captchaEnabled) {
            return false;
        }

        return $conversation === null || $conversation->isClosed();
    }

    /**
     * Можно ли принять это сообщение.
     *
     * `$conversation` = null или закрытый разговор означает, что панель
     * собирается завести новый: тогда добавляется потолок на новые
     * разговоры с адреса. Он ловит ровно один приём — чистку куки,
     * чтобы начать всё сначала с пустыми счётчиками.
     */
    public function inspect(
        ?ChatConversation $conversation,
        string $body,
        ?Request $request = null,
        bool $isStaff = false,
    ): ChatAbuseVerdict {
        $request ??= request();

        /*
         * Робот. Молча и первым делом: краулер до эндпоинта доходить не
         * должен вообще (виджет не в DOM, пока по нему не кликнули), но
         * если дошёл — денег на него не тратим и подсказок не даём.
         */
        if ($this->isRobot($request)) {
            return ChatAbuseVerdict::silent('robot');
        }

        $length = mb_strlen(trim($body));

        if ($length < $this->minLength) {
            return ChatAbuseVerdict::reject('Слишком короткий вопрос.', 'too_short');
        }

        if ($length > $this->maxLength) {
            return ChatAbuseVerdict::reject('Слишком длинный вопрос — сократите, пожалуйста.', 'too_long');
        }

        $verdict = $this->counters($conversation, $request, $isStaff);

        if (! $verdict->allowed()) {
            return $verdict;
        }

        if ($this->budgetExhausted()) {
            return ChatAbuseVerdict::budget('daily_budget');
        }

        return ChatAbuseVerdict::allow();
    }

    /**
     * То же самое для действия без текста — «Позвать менеджера».
     *
     * Отличий от `inspect()` ровно два, и оба существенные. Длину мерить
     * нечего. А дневной бюджет здесь НЕ проверяется намеренно: он про
     * деньги на модель, а зов человека не стоит ни токена — запрещать
     * его в тот момент, когда бот замолчал, значило бы отнимать у
     * посетителя единственный оставшийся выход.
     */
    public function inspectAction(
        ?ChatConversation $conversation,
        ?Request $request = null,
        bool $isStaff = false,
    ): ChatAbuseVerdict {
        $request ??= request();

        if ($this->isRobot($request)) {
            return ChatAbuseVerdict::silent('robot');
        }

        return $this->counters($conversation, $request, $isStaff);
    }

    /**
     * Кулдаун и счётчики — общая часть обеих проверок.
     */
    private function counters(
        ?ChatConversation $conversation,
        Request $request,
        bool $isStaff = false,
    ): ChatAbuseVerdict {
        /*
         * Сотрудник магазина в потолки не упирается.
         *
         * Не поблажка своим, а условие работы: приёмка чата — это десятки
         * разговоров подряд с чисткой переписки между ними, и у донора
         * потолок «новых разговоров с адреса» дважды встал поперёк проверки.
         * Счётчики стерегут анонимного посетителя, а сотрудник уже опознан
         * и отвечает за себя.
         *
         * ДНЕВНОЙ БЮДЖЕТ на него всё равно распространяется: тесты стоят
         * тех же денег, что и покупатели, а ключ шлюза здесь общий с turbo —
         * молча выедать его никому не позволено.
         */
        if ($isStaff) {
            return ChatAbuseVerdict::allow();
        }

        $ipHash = $this->ipHash($request);

        if ($conversation === null || $conversation->isClosed()) {
            if (RateLimiter::tooManyAttempts($this->newConversationKey($ipHash), $this->newConversationsPerIpDay)) {
                return ChatAbuseVerdict::reject(
                    'Слишком много обращений за сегодня. Оставьте почту — менеджер ответит письмом.',
                    'new_conversations_per_ip',
                );
            }
        } else {
            // Ноль отключает паузу целиком — вместе с чтением ключа,
            // который мог остаться от прежней настройки.
            if ($this->cooldownSeconds > 0 && Cache::has($this->cooldownKey($conversation))) {
                return ChatAbuseVerdict::reject('Не так быстро — дайте секунду на ответ.', 'cooldown');
            }

            if (RateLimiter::tooManyAttempts($this->conversationHourKey($conversation), $this->perConversationHour)) {
                return ChatAbuseVerdict::reject(
                    'Слишком много вопросов подряд. Подождите немного, пожалуйста.',
                    'per_conversation_hour',
                );
            }

            if (RateLimiter::tooManyAttempts($this->conversationDayKey($conversation), $this->perConversationDay)) {
                return ChatAbuseVerdict::reject(
                    'На сегодня вопросов достаточно. Оставьте почту — менеджер ответит письмом.',
                    'per_conversation_day',
                );
            }
        }

        if (RateLimiter::tooManyAttempts($this->ipKey($ipHash), $this->perIpDay)) {
            return ChatAbuseVerdict::reject(
                'Слишком много обращений. Оставьте почту — менеджер ответит письмом.',
                'per_ip_day',
            );
        }

        return ChatAbuseVerdict::allow();
    }

    private function isRobot(Request $request): bool
    {
        return (new Agent)->isRobot((string) $request->userAgent());
    }

    /**
     * Сообщение принято — поднимаем счётчики.
     *
     * Отдельным вызовом, а не внутри `inspect()`, по одной причине:
     * между проверкой и записью сообщения стоит ещё несколько условий
     * (бот выключен, предыдущий ответ ещё готовится), и квоту не должен
     * тратить вопрос, который так и не был задан.
     */
    public function remember(ChatConversation $conversation, ?Request $request = null): void
    {
        $request ??= request();

        if ($this->cooldownSeconds > 0) {
            Cache::put($this->cooldownKey($conversation), true, $this->cooldownSeconds);
        }

        RateLimiter::hit($this->conversationHourKey($conversation), 3600);
        RateLimiter::hit($this->conversationDayKey($conversation), 86400);
        RateLimiter::hit($this->ipKey($this->ipHash($request)), 86400);

        $this->bump('messages', 1);
    }

    /** Новый разговор с этого адреса. */
    public function rememberNewConversation(?Request $request = null): void
    {
        RateLimiter::hit($this->newConversationKey($this->ipHash($request ?? request())), 86400);
    }

    /**
     * Оценка «помог ответ?» — открытый наружу вызов, который пишет
     * в базу. Потолок нужен и здесь, но отдельный и щедрый: в него не
     * упрётся ни один живой человек, а объяснять тому, кто упёрся,
     * нечего — отказ молчаливый.
     */
    public function allowsRating(ChatConversation $conversation): bool
    {
        return ! RateLimiter::tooManyAttempts($this->ratingKey($conversation), self::RATINGS_PER_MINUTE);
    }

    public function rememberRating(ChatConversation $conversation): void
    {
        RateLimiter::hit($this->ratingKey($conversation), 60);
    }

    /**
     * Токены, потраченные на ход. Вызывается ПОСЛЕ ответа модели, в том
     * числе когда ответ выброшен (оператор перехватил разговор): деньги
     * за него уже списаны, и бюджет обязан их видеть.
     */
    public function recordSpend(int $tokens): void
    {
        $this->bump('tokens', $tokens);
    }

    /**
     * Кончился ли дневной бюджет магазина.
     *
     * Два потолка, и достаточно любого: сообщения ловят «бот отвечает
     * слишком многим», токены — «бот отвечает слишком длинно». Второе
     * без первого случается при зацикливании на инструментах, когда
     * ходов мало, а ввода на каждом — десятки тысяч токенов.
     */
    public function budgetExhausted(): bool
    {
        if ($this->dailyMessages > 0 && $this->spent('messages') >= $this->dailyMessages) {
            return true;
        }

        return $this->dailyTokens > 0 && $this->spent('tokens') >= $this->dailyTokens;
    }

    /**
     * Сколько истрачено за сегодня — для виджета расхода над «Диалогами».
     *
     * @return array{messages: int, tokens: int, daily_messages: int, daily_tokens: int}
     */
    public function spentToday(): array
    {
        return [
            'messages' => $this->spent('messages'),
            'tokens' => $this->spent('tokens'),
            'daily_messages' => $this->dailyMessages,
            'daily_tokens' => $this->dailyTokens,
        ];
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    public function maxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Адрес не храним нигде — ни в счётчике, ни в базе: сопоставить два
     * обращения хэш позволяет, а посетителя не описывает. Тот же приём,
     * что и в `chat_conversations.ip_hash`.
     */
    private function ipHash(Request $request): string
    {
        return hash('sha256', (string) $request->ip());
    }

    private function cooldownKey(ChatConversation $conversation): string
    {
        return 'chat:abuse:cooldown:'.$conversation->getKey();
    }

    private function conversationHourKey(ChatConversation $conversation): string
    {
        return 'chat:abuse:conv-hour:'.$conversation->getKey();
    }

    private function conversationDayKey(ChatConversation $conversation): string
    {
        return 'chat:abuse:conv-day:'.$conversation->getKey();
    }

    private function ipKey(string $ipHash): string
    {
        return 'chat:abuse:ip-day:'.$ipHash;
    }

    private function newConversationKey(string $ipHash): string
    {
        return 'chat:abuse:ip-new:'.$ipHash;
    }

    private function ratingKey(ChatConversation $conversation): string
    {
        return 'chat:abuse:rating:'.$conversation->getKey();
    }

    private function spent(string $bucket): int
    {
        return (int) Cache::get($this->budgetKey($bucket), 0);
    }

    private function bump(string $bucket, int $by): void
    {
        if ($by <= 0) {
            return;
        }

        $key = $this->budgetKey($bucket);

        // add() перед increment() обязателен: инкремент несуществующего
        // ключа в Redis создаёт его БЕЗ срока жизни, и счётчик первых
        // суток остался бы вечным.
        Cache::add($key, 0, $this->secondsUntilMidnight());
        Cache::increment($key, $by);
    }

    /**
     * Ключ дневного счётчика. Сутки считаются по часовому поясу магазина,
     * а не по UTC: «дневной бюджет» админ понимает как свой рабочий день,
     * и сброс в три часа ночи по Москве объяснять было бы нечем.
     */
    private function budgetKey(string $bucket): string
    {
        return 'chat:abuse:budget:'.$bucket.':'.now($this->dayTimezone)->format('Y-m-d');
    }

    private function secondsUntilMidnight(): int
    {
        // Порядок аргументов не переставлять: в Carbon 3 разность знаковая,
        // и «от полуночи до сейчас» дало бы отрицательный срок жизни.
        $now = now($this->dayTimezone);

        return max(60, (int) $now->diffInSeconds($now->copy()->endOfDay()));
    }
}
