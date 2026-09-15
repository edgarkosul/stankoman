<?php

use App\Jobs\GenerateChatReplyJob;
use App\Livewire\Common\RequestCallback;
use App\Livewire\Support\ChatPanel;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatAbuseGuard;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Намордник на панели: что посетитель видит, когда упёрся в потолок.
 *
 * Сами потолки посчитаны в tests/Unit/Chat/ChatAbuseGuardTest.php без базы
 * и без браузера — здесь проверяется другое: что вердикт превращается
 * в правильный исход. Их три, и они не взаимозаменяемы: роботу — тишина,
 * человеку — объяснение, исчерпанному бюджету — менеджер.
 *
 * Робота среди них нет, и не потому, что он не важен: тестовая обвязка
 * Livewire на каждое действие собирает запрос заново и свои заголовки
 * в него не переносит (`SubsequentRender` перезаписывает их одним
 * `X-Livewire`), то есть подсунуть User-Agent краулера в `send()` нечем.
 * Вердикт на роботе проверен в unit-тесте, ветка здесь — одна строка.
 */

beforeEach(function (): void {
    Queue::fake();
});

function abuseCookieName(): string
{
    return (string) config('ai_support.chat.cookie');
}

/** Пересобрать намордник после правки конфига: в контейнере он синглтон. */
function rebuiltGuard(array $config): void
{
    config($config);
    app()->forgetInstance(ChatAbuseGuard::class);
}

it('не принимает второй вопрос сразу за первым', function (): void {
    $conversation = ChatConversation::factory()->create();

    // Первый вопрос только что задан — счётчики подняты, пауза идёт.
    app(ChatAbuseGuard::class)->remember($conversation);

    Livewire::withCookie(abuseCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->set('draft', 'А в Тюмень везёте?')
        ->call('send')
        ->assertHasErrors('draft')
        ->assertSee('Не так быстро');

    expect($conversation->messages()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('на исчерпанном дневном бюджете передаёт вопрос менеджеру, а не отказывает', function (): void {
    rebuiltGuard([
        'ai_support.chat.abuse.daily_tokens' => 1000,
        'ai_support.chat.abuse.cooldown_seconds' => 0,
    ]);

    // Бот уже проговорил сегодняшний потолок токенов.
    app(ChatAbuseGuard::class)->recordSpend(1000);

    $conversation = ChatConversation::factory()->create();

    $component = Livewire::withCookie(abuseCookieName(), $conversation->token)->test(ChatPanel::class);

    $component->set('draft', 'А ответить-то можете?')
        ->call('send')
        ->assertHasNoErrors()
        // Вопрос принят, но ждать бота не заставляем: отвечает человек,
        // и контакты предлагаются сразу.
        ->assertSet('awaiting', false)
        ->assertSee('Передал ваш вопрос менеджеру')
        ->assertSee(RequestCallback::CONTACT_BUTTON);

    $conversation->refresh();

    expect($conversation->messages()->where('role', ChatMessage::ROLE_VISITOR)->count())->toBe(1)
        ->and($conversation->escalated_at)->not->toBeNull()
        ->and($conversation->messages()
            ->where('role', ChatMessage::ROLE_ASSISTANT)
            ->latest('id')
            ->first()
            ->stop_reason)->toBe('daily_budget');

    // Модель не зовём: денег на ответ нет.
    Queue::assertNotPushed(GenerateChatReplyJob::class);

    /*
     * Дальше молчим: бюджет держится до полуночи, и повторять «передал
     * менеджеру» на каждый вопрос значило бы засыпать ленту одинаковыми
     * репликами, а менеджера — уведомлениями.
     */
    $component->set('draft', 'И ещё вопрос')->call('send')->assertHasNoErrors();

    expect($conversation->fresh()->messages()->where('stop_reason', 'daily_budget')->count())->toBe(1)
        ->and($conversation->fresh()->messages()->where('role', ChatMessage::ROLE_VISITOR)->count())->toBe(2);
});

it('не считает потолки сотруднику магазина', function (): void {
    config(['settings.general.filament_admin_emails' => ['admin@intertooler.test']]);

    rebuiltGuard(['ai_support.chat.abuse.new_conversations_per_ip_day' => 1]);

    // Потолок новых разговоров с адреса уже выбран.
    app(ChatAbuseGuard::class)->rememberNewConversation();

    Livewire::test(ChatPanel::class)
        ->set('draft', 'Проверяю чат')
        ->call('send')
        ->assertHasErrors('draft')
        ->assertSee('Слишком много обращений за сегодня');

    $this->actingAs(User::factory()->create(['email' => 'admin@intertooler.test']));

    Livewire::test(ChatPanel::class)
        ->set('draft', 'Проверяю чат сотрудником')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('awaiting', true);

    expect(ChatConversation::query()->count())->toBe(1);
});

it('держит щедрый потолок на оценках', function (): void {
    $conversation = ChatConversation::factory()->create();
    $answer = ChatMessage::factory()->for($conversation, 'conversation')->fromAssistant()->create();

    $guard = app(ChatAbuseGuard::class);

    while ($guard->allowsRating($conversation)) {
        $guard->rememberRating($conversation);
    }

    Livewire::withCookie(abuseCookieName(), $conversation->token)
        ->test(ChatPanel::class)
        ->call('rate', $answer->id, -1);

    // Молча: объяснять заклинившей мыши нечего.
    expect($answer->fresh()->rating)->toBeNull();
});
