<?php

use App\Models\ChatConversation;
use App\Services\Chat\ChatAbuseGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Намордник — единственная часть чата, которую можно проверить целиком
 * без базы, без очереди и без сети: он работает с кэшем и текстом.
 * Ради этого он и вынесен из Livewire-компонента, где тест потребовал бы
 * браузера.
 *
 * Разговор здесь НЕ сохраняется в базу: стражу нужен только его id,
 * а модель с проставленным ключом — обычный объект в памяти.
 */

function abuseGuard(
    int $minLength = 2,
    int $maxLength = 1000,
    int $cooldown = 3,
    int $perConversationHour = 15,
    int $perConversationDay = 30,
    int $perIpDay = 150,
    int $newConversations = 10,
    int $dailyMessages = 500,
    int $dailyTokens = 2000000,
    bool $captchaEnabled = false,
): ChatAbuseGuard {
    return new ChatAbuseGuard(
        minLength: $minLength,
        maxLength: $maxLength,
        cooldownSeconds: $cooldown,
        perConversationHour: $perConversationHour,
        perConversationDay: $perConversationDay,
        perIpDay: $perIpDay,
        newConversationsPerIpDay: $newConversations,
        dailyMessages: $dailyMessages,
        dailyTokens: $dailyTokens,
        captchaEnabled: $captchaEnabled,
    );
}

function abuseConversation(int $id = 1, string $status = ChatConversation::STATUS_BOT): ChatConversation
{
    $conversation = new ChatConversation;
    $conversation->forceFill(['id' => $id, 'status' => $status]);

    return $conversation;
}

function visitorRequest(string $ip = '10.0.0.1', string $agent = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/126'): Request
{
    return Request::create('/livewire/update', 'POST', server: [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => $agent,
    ]);
}

it('пропускает обычный вопрос', function (): void {
    $verdict = abuseGuard()->inspect(abuseConversation(), 'Есть ли доставка в Мурманск?', visitorRequest());

    expect($verdict->allowed())->toBeTrue();
});

it('молча выходит на роботе — сообщение об ошибке было бы подсказкой', function (): void {
    $verdict = abuseGuard()->inspect(
        abuseConversation(),
        'Есть ли доставка в Мурманск?',
        visitorRequest(agent: 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'),
    );

    expect($verdict->isSilent())->toBeTrue()
        ->and($verdict->message)->toBeNull()
        ->and($verdict->reason)->toBe('robot');
});

it('меряет длину по символам, а не по байтам', function (): void {
    // Кириллица в UTF-8 занимает два байта, и strlen отрезал бы вопрос
    // ровно вдвое раньше обещанного счётчиком под полем ввода.
    $verdict = abuseGuard(maxLength: 10)->inspect(abuseConversation(), str_repeat('я', 10), visitorRequest());

    expect($verdict->allowed())->toBeTrue();

    $verdict = abuseGuard(maxLength: 10)->inspect(abuseConversation(), str_repeat('я', 11), visitorRequest());

    expect($verdict->allowed())->toBeFalse()
        ->and($verdict->reason)->toBe('too_long');
});

it('отсекает случайный Enter', function (): void {
    $verdict = abuseGuard()->inspect(abuseConversation(), ' а ', visitorRequest());

    expect($verdict->reason)->toBe('too_short')
        ->and($verdict->message)->toBe('Слишком короткий вопрос.');
});

it('держит паузу между сообщениями', function (): void {
    $guard = abuseGuard();
    $conversation = abuseConversation();

    $guard->remember($conversation, visitorRequest());

    expect($guard->inspect($conversation, 'А второй вопрос?', visitorRequest())->reason)->toBe('cooldown');
});

it('не тратит квоту на отвергнутое сообщение', function (): void {
    // Человек, промахнувшийся длиной, не должен платить за это своей же
    // квотой на день: счётчики поднимает только remember().
    $guard = abuseGuard(perConversationHour: 1);
    $conversation = abuseConversation();

    $guard->inspect($conversation, 'а', visitorRequest());
    $guard->inspect($conversation, 'а', visitorRequest());

    expect($guard->inspect($conversation, 'Нормальный вопрос', visitorRequest())->allowed())->toBeTrue();
});

it('считает потолок на разговор в час', function (): void {
    $guard = abuseGuard(cooldown: 0, perConversationHour: 2);
    $conversation = abuseConversation();

    $guard->remember($conversation, visitorRequest());
    $guard->remember($conversation, visitorRequest());

    expect($guard->inspect($conversation, 'Третий вопрос за час', visitorRequest())->reason)
        ->toBe('per_conversation_hour');
});

it('считает потолок на разговор в сутки отдельно от часового', function (): void {
    // Суточный потолок обязан срабатывать сам по себе: часовой к концу
    // смены давно обнулился, а поток вопросов из одного разговора — нет.
    $guard = abuseGuard(cooldown: 0, perConversationHour: 100, perConversationDay: 2);
    $conversation = abuseConversation();

    $guard->remember($conversation, visitorRequest());
    $guard->remember($conversation, visitorRequest());

    expect($guard->inspect($conversation, 'Третий вопрос за сутки', visitorRequest())->reason)
        ->toBe('per_conversation_day');
});

it('считает потолок на адрес поверх потолков разговора', function (): void {
    // Диалоги разные, адрес один: так выглядит и офис за NAT, и тот,
    // кто чистит куку между вопросами. У нас магазин продаёт организациям —
    // поэтому потолок на адрес щедрый, но он есть.
    $guard = abuseGuard(cooldown: 0, perIpDay: 2);

    $guard->remember(abuseConversation(1), visitorRequest());
    $guard->remember(abuseConversation(2), visitorRequest());

    expect($guard->inspect(abuseConversation(3), 'Третий вопрос с адреса', visitorRequest())->reason)
        ->toBe('per_ip_day');
});

it('не путает адреса между собой', function (): void {
    $guard = abuseGuard(cooldown: 0, perIpDay: 1);

    $guard->remember(abuseConversation(1), visitorRequest(ip: '10.0.0.1'));

    expect($guard->inspect(abuseConversation(2), 'Вопрос с другого адреса', visitorRequest(ip: '10.0.0.2'))->allowed())
        ->toBeTrue();
});

it('ограничивает число новых разговоров с адреса', function (): void {
    // Единственный смысл этого потолка — тот, кто чистит куку, чтобы
    // начать заново с пустыми счётчиками разговора.
    $guard = abuseGuard(newConversations: 2);

    $guard->rememberNewConversation(visitorRequest());
    $guard->rememberNewConversation(visitorRequest());

    expect($guard->inspect(null, 'Третий разговор за сутки', visitorRequest())->reason)
        ->toBe('new_conversations_per_ip');
});

it('считает закрытый разговор началом нового', function (): void {
    $guard = abuseGuard(newConversations: 1);

    $guard->rememberNewConversation(visitorRequest());

    $verdict = $guard->inspect(
        abuseConversation(status: ChatConversation::STATUS_CLOSED),
        'Оператор закрыл, спрашиваю снова',
        visitorRequest(),
    );

    expect($verdict->reason)->toBe('new_conversations_per_ip');
});

it('не спрашивает кулдаун у ещё не существующего разговора', function (): void {
    // Первое сообщение приходит без диалога вовсе, и ключа кулдауна
    // для него нет — проверять по несуществующему id значило бы
    // отказывать всем первым вопросам подряд.
    expect(abuseGuard()->inspect(null, 'Первый вопрос', visitorRequest())->allowed())->toBeTrue();
});

it('выдаёт бюджетный исход, а не отказ, когда кончились деньги на день', function (): void {
    $guard = abuseGuard(cooldown: 0, dailyMessages: 2);
    $conversation = abuseConversation();

    $guard->remember($conversation, visitorRequest());
    $guard->remember($conversation, visitorRequest());

    $verdict = $guard->inspect($conversation, 'Третий вопрос за день', visitorRequest());

    expect($verdict->isBudget())->toBeTrue()
        ->and($verdict->allowed())->toBeFalse()
        // Посетителю ничего не пишем этим вердиктом: ответ ему даст
        // эскалация, а не сообщение об ошибке под полем ввода.
        ->and($verdict->message)->toBeNull();
});

it('ловит бюджет по токенам, даже если ходов было мало', function (): void {
    // Зацикливание на инструментах: ходов три, а ввода на каждом —
    // десятки тысяч токенов. По сообщениям это не поймать.
    $guard = abuseGuard(dailyTokens: 1000);

    $guard->recordSpend(600);

    expect($guard->budgetExhausted())->toBeFalse();

    $guard->recordSpend(600);

    expect($guard->budgetExhausted())->toBeTrue();
});

it('не ограничивает бюджет нулём', function (): void {
    $guard = abuseGuard(dailyMessages: 0, dailyTokens: 0);

    $guard->recordSpend(10_000_000);

    expect($guard->budgetExhausted())->toBeFalse();
});

it('считает сутки бюджета по Москве, а не по UTC приложения', function (): void {
    /*
     * `app.timezone` здесь UTC, и на московских сутках это расходится:
     * в 01:00 по Москве в Гринвиче ещё вчера. Взяли бы `now()` — потолок
     * обнулялся бы в три часа ночи по Москве, чего владельцу магазина
     * объяснить нечем.
     */
    $this->travelTo(CarbonImmutable::parse('2026-09-16 22:30', 'UTC'));

    abuseGuard()->recordSpend(5);

    // 2026-09-17 в Москве, 2026-09-16 в UTC.
    expect(Cache::get('chat:abuse:budget:tokens:2026-09-17'))->toBe(5)
        ->and(Cache::get('chat:abuse:budget:tokens:2026-09-16'))->toBeNull();
});

it('даёт дневному счётчику срок жизни — иначе он остался бы вечным', function (): void {
    // Инкремент несуществующего ключа в Redis создаёт его БЕЗ TTL,
    // и бюджет первых суток никогда бы не обнулился.
    abuseGuard()->recordSpend(5);

    $key = 'chat:abuse:budget:tokens:'.now('Europe/Moscow')->format('Y-m-d');

    expect(Cache::get($key))->toBe(5);
});

it('показывает израсходованное за сегодня вместе с потолками', function (): void {
    $guard = abuseGuard(dailyMessages: 500, dailyTokens: 2000);

    $guard->remember(abuseConversation(), visitorRequest());
    $guard->recordSpend(1200);

    expect($guard->spentToday())->toBe([
        'messages' => 1,
        'tokens' => 1200,
        'daily_messages' => 500,
        'daily_tokens' => 2000,
    ]);
});

it('пускает «позвать менеджера» при исчерпанном бюджете', function (): void {
    // Зов человека не стоит ни токена, а запрет на него в тот момент,
    // когда бот замолчал, закрыл бы посетителю последний выход.
    $guard = abuseGuard(cooldown: 0, dailyMessages: 1);
    $conversation = abuseConversation();

    $guard->remember($conversation, visitorRequest());

    expect($guard->budgetExhausted())->toBeTrue()
        ->and($guard->inspect($conversation, 'А ответить-то можете?', visitorRequest())->isBudget())->toBeTrue()
        ->and($guard->inspectAction($conversation, visitorRequest())->allowed())->toBeTrue();
});

it('не пускает робота даже к кнопке «позвать менеджера»', function (): void {
    $verdict = abuseGuard()->inspectAction(
        abuseConversation(),
        visitorRequest(agent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),
    );

    expect($verdict->isSilent())->toBeTrue();
});

it('держит отдельный щедрый потолок на оценки', function (): void {
    $guard = abuseGuard();
    $conversation = abuseConversation();

    for ($i = 0; $i < 30; $i++) {
        expect($guard->allowsRating($conversation))->toBeTrue();
        $guard->rememberRating($conversation);
    }

    expect($guard->allowsRating($conversation))->toBeFalse()
        // Потолок оценок не имеет отношения к вопросам: заклинившая
        // мышь не должна отнимать у человека право спросить.
        ->and($guard->inspect($conversation, 'А вопрос всё равно можно?', visitorRequest())->allowed())->toBeTrue();
});

it('спрашивает капчу только на первом сообщении разговора', function (): void {
    $guard = abuseGuard(captchaEnabled: true);

    expect($guard->needsCaptcha(null))->toBeTrue()
        // Разговор закрыл оператор — следующее сообщение заводит новый,
        // и проверить его надо так же, как самый первый.
        ->and($guard->needsCaptcha(abuseConversation(status: ChatConversation::STATUS_CLOSED)))->toBeTrue()
        // А в живом разговоре посетителя удостоверяет кука: полсекунды
        // на каждую реплику ради уже пройденной проверки не платим.
        ->and($guard->needsCaptcha(abuseConversation()))->toBeFalse();
});

it('не спрашивает капчу, когда она выключена в окружении', function (): void {
    // На деве ключей нет и рубильник выключен: включённая проверка означала
    // бы наглухо закрытый чат — скрипт в браузере не поднимется, токена
    // не будет.
    expect(abuseGuard()->needsCaptcha(null))->toBeFalse();
});

it('не считает потолки сотруднику магазина', function (): void {
    // Приёмка чата — это десятки разговоров подряд с чисткой переписки
    // между ними, и у донора потолок «новых разговоров с адреса» дважды
    // встал поперёк проверки. Счётчики стерегут анонима, а сотрудник
    // уже опознан.
    $guard = abuseGuard(newConversations: 1, perIpDay: 1, perConversationHour: 1);
    $conversation = abuseConversation();

    $guard->rememberNewConversation(visitorRequest());
    $guard->remember($conversation, visitorRequest());

    expect($guard->inspect($conversation, 'Проверяю чат', visitorRequest())->allowed())->toBeFalse()
        ->and($guard->inspect($conversation, 'Проверяю чат', visitorRequest(), isStaff: true)->allowed())->toBeTrue()
        ->and($guard->inspect(null, 'И новый разговор тоже', visitorRequest(), isStaff: true)->allowed())->toBeTrue()
        ->and($guard->inspectAction($conversation, visitorRequest(), isStaff: true)->allowed())->toBeTrue();
});

it('дневной бюджет действует и на сотрудника', function (): void {
    // Ключ шлюза общий с turbo: тесты стоят тех же денег, что и покупатели.
    $guard = abuseGuard(cooldown: 0, dailyMessages: 1);
    $guard->remember(abuseConversation(), visitorRequest());

    expect($guard->inspect(abuseConversation(), 'Ещё вопрос', visitorRequest(), isStaff: true)->isBudget())->toBeTrue();
});
