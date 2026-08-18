<?php

use App\Filament\Pages\PricingSurvey;
use App\Models\SurveyResponse;
use App\Models\User;
use Livewire\Livewire;

function pricingSurveyAdmin(): User
{
    config(['settings.general.filament_admin_emails' => ['admin@example.com']]);

    return User::factory()->create(['email' => 'admin@example.com']);
}

function pricingSurveyFullAnswers(): array
{
    $answers = [];

    foreach (PricingSurvey::questions() as $question) {
        $answers[$question['id']] = $question['options'][0]['key'];
    }

    return $answers;
}

test('анкета открывается только админам панели', function (): void {
    $admin = pricingSurveyAdmin();
    $customer = User::factory()->create(['email' => 'customer@example.com']);

    $this->actingAs($admin)
        ->get(PricingSurvey::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Восемь вопросов о ценах');

    $this->actingAs($customer)
        ->get(PricingSurvey::getUrl(panel: 'admin'))
        ->assertForbidden();
});

test('ответы сохраняются и показываются на странице', function (): void {
    $admin = pricingSurveyAdmin();
    $answers = pricingSurveyFullAnswers();

    Livewire::actingAs($admin)
        ->test(PricingSurvey::class)
        ->call('submit', $answers)
        ->assertSet('saved', $answers)
        ->assertNotified();

    $response = SurveyResponse::latestFor(SurveyResponse::SURVEY_PRICING);

    expect($response)->not->toBeNull()
        ->and($response->answers)->toBe($answers)
        ->and($response->user_id)->toBe($admin->id);
});

test('неполные ответы не сохраняются', function (): void {
    $admin = pricingSurveyAdmin();

    $answers = pricingSurveyFullAnswers();
    unset($answers['sku']);

    Livewire::actingAs($admin)
        ->test(PricingSurvey::class)
        ->call('submit', $answers)
        ->assertSet('saved', []);

    expect(SurveyResponse::latestFor(SurveyResponse::SURVEY_PRICING))->toBeNull();
});

test('недопустимый вариант ответа отклоняется', function (): void {
    $admin = pricingSurveyAdmin();

    $answers = pricingSurveyFullAnswers();
    $answers['sku'] = 'Ъ';

    Livewire::actingAs($admin)
        ->test(PricingSurvey::class)
        ->call('submit', $answers);

    expect(SurveyResponse::latestFor(SurveyResponse::SURVEY_PRICING))->toBeNull();
});
