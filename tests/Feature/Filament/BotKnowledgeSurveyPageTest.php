<?php

use App\Filament\Pages\BotKnowledgeSurvey;
use App\Models\SurveyResponse;
use App\Models\User;
use Livewire\Livewire;

function botSurveyAdmin(): User
{
    config(['settings.general.filament_admin_emails' => ['admin@example.com']]);

    return User::factory()->create(['email' => 'admin@example.com']);
}

test('вопросы для бота открываются только админам панели', function (): void {
    $admin = botSurveyAdmin();
    $customer = User::factory()->create(['email' => 'customer@example.com']);

    $this->actingAs($admin)
        ->get(BotKnowledgeSurvey::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Научим бота отвечать так, как отвечает ваш магазин');

    $this->actingAs($customer)
        ->get(BotKnowledgeSurvey::getUrl(panel: 'admin'))
        ->assertForbidden();
});

test('у пункта меню нет иконки: в ветке бота у группы «ИИ бот» свой значок', function (): void {
    // Filament роняет весь сайдбар, если иконка есть и у группы, и у пункта.
    expect(BotKnowledgeSurvey::getNavigationIcon())->toBeNull()
        ->and(BotKnowledgeSurvey::getNavigationGroup())->toBe('ИИ бот');
});

test('автосохранение держит один черновик и чистит ответы', function (): void {
    $admin = botSurveyAdmin();

    $component = Livewire::actingAs($admin)->test(BotKnowledgeSurvey::class);

    $first = $component->instance()->saveDraft(['deferral' => ['rule' => 'no', 'chuzhoe' => 'x']]);
    $component->instance()->saveDraft(['deferral' => ['rule' => 'sometimes', 'terms' => 'до 14 дней']]);

    $drafts = SurveyResponse::query()->where('survey', SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT)->get();

    expect($first['answered'])->toBe(1)
        ->and($drafts)->toHaveCount(1)
        ->and($drafts->first()->answers)->toBe(['deferral' => ['rule' => 'sometimes', 'terms' => 'до 14 дней']])
        ->and($drafts->first()->user_id)->toBe($admin->id)
        ->and(SurveyResponse::latestFor(SurveyResponse::SURVEY_BOT_KNOWLEDGE))->toBeNull()
        ->and(BotKnowledgeSurvey::getNavigationBadge())->toBe('новое');
});

test('отправка сохраняет неполные ответы отдельной строкой', function (): void {
    $admin = botSurveyAdmin();

    $result = Livewire::actingAs($admin)
        ->test(BotKnowledgeSurvey::class)
        ->instance()
        ->submit(['credit' => ['available' => 'no', 'leasing' => 'no']]);

    $response = SurveyResponse::latestFor(SurveyResponse::SURVEY_BOT_KNOWLEDGE);

    expect($result['ok'])->toBeTrue()
        ->and($result['submitted']['answered'])->toBe(1)
        ->and($response->answers)->toBe(['credit' => ['available' => 'no', 'leasing' => 'no']])
        ->and(SurveyResponse::latestFor(SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT)->answers)->toBe($response->answers)
        ->and(BotKnowledgeSurvey::getNavigationBadge())->toBeNull();
});

test('пустая отправка ничего не пишет', function (): void {
    $admin = botSurveyAdmin();

    $result = Livewire::actingAs($admin)
        ->test(BotKnowledgeSurvey::class)
        ->instance()
        ->submit(['credit' => ['note' => 'подумаю', 'available' => 'может быть']]);

    expect($result['ok'])->toBeFalse()
        ->and(SurveyResponse::query()->count())->toBe(0);
});

test('страница продолжает с черновика', function (): void {
    $admin = botSurveyAdmin();

    SurveyResponse::query()->create([
        'survey' => SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT,
        'user_id' => $admin->id,
        'answers' => ['prices' => ['rule' => 'try']],
    ]);

    Livewire::actingAs($admin)
        ->test(BotKnowledgeSurvey::class)
        ->assertViewHas('config', fn (array $config): bool => (array) $config['answers'] === ['prices' => ['rule' => 'try']]
            && $config['submitted'] === null
            && $config['savedLabel'] !== null);
});

test('команда выгружает отправленные ответы и черновик', function (): void {
    $admin = botSurveyAdmin();

    $this->artisan('survey:bot-knowledge')
        ->expectsOutputToContain('Отправленных ответов нет')
        ->assertSuccessful();

    SurveyResponse::query()->create([
        'survey' => SurveyResponse::SURVEY_BOT_KNOWLEDGE,
        'user_id' => $admin->id,
        'answers' => ['volume_discount' => ['rule' => 'manager']],
    ]);

    SurveyResponse::query()->create([
        'survey' => SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT,
        'user_id' => $admin->id,
        'answers' => ['volume_discount' => ['rule' => 'scale', 'scale' => 'от 300 000 ₽ — 3%']],
    ]);

    $this->artisan('survey:bot-knowledge')
        ->expectsOutputToContain('Отвечено 1 из')
        ->expectsOutputToContain('- Скидка от объёма закупки: Как в KratonShop: есть, считает менеджер')
        ->assertSuccessful();

    $this->artisan('survey:bot-knowledge --draft')
        ->expectsOutputToContain('- Какая шкала: от 300 000 ₽ — 3%')
        ->assertSuccessful();
});
