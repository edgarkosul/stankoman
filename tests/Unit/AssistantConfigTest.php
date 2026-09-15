<?php

use App\Services\Ai\AssistantConfig;
use Tests\TestCase;

// Настраиваемый слой читается из конфига, куда его раскладывает
// SettingsServiceProvider. Значит проверяется без базы — так и надо:
// решение «отвечает бот или нет» принимается на каждом вопросе.
uses(TestCase::class);

function assistantConfigWith(array $settings = [], bool $envEnabled = true): AssistantConfig
{
    config()->set('ai_support.agent.enabled', $envEnabled);

    foreach ($settings as $key => $value) {
        config()->set('settings.'.AssistantConfig::PREFIX.$key, $value);
    }

    return new AssistantConfig(shopName: 'Интертулер');
}

beforeEach(function (): void {
    // Настройки живут в общем конфиге, и один тест не должен протекать
    // в следующий: провайдер разложил бы их заново, а мы кладём руками.
    config()->set('settings.assistant', []);
});

it('без настройки считает бота включённым', function (): void {
    // Иначе первый же выкат погасил бы виджет молча, до того как
    // владелец вообще узнает о существовании выключателя.
    expect(assistantConfigWith()->enabled())->toBeTrue()
        ->and(assistantConfigWith()->disabledByAdmin())->toBeFalse();
});

it('выключается в админке', function (): void {
    $config = assistantConfigWith(['enabled' => false]);

    expect($config->enabled())->toBeFalse()
        ->and($config->disabledByAdmin())->toBeTrue();
});

it('аварийный рубильник в .env перебивает включённый выключатель', function (): void {
    $config = assistantConfigWith(['enabled' => true], envEnabled: false);

    expect($config->enabled())->toBeFalse()
        // И это НЕ вина админки: доктор должен назвать виновника верно,
        // иначе владелец будет щёлкать выключателем, а бот молчать.
        ->and($config->disabledByAdmin())->toBeFalse();
});

it('собирает редактируемый слой промпта списками', function (): void {
    $settings = assistantConfigWith([
        'about' => '  Магазин промышленного оборудования.  ',
        'rules' => ['Счёт в день обращения', '  ', 'Доставка по России транспортными компаниями'],
        'forbidden_topics' => ['торг'],
        'refusal' => 'Отвечаю только про магазин.',
    ])->promptSettings();

    expect($settings['about'])->toBe('Магазин промышленного оборудования.')
        ->and($settings['rules'])->toBe("- Счёт в день обращения\n- Доставка по России транспортными компаниями")
        ->and($settings['forbidden_topics'])->toBe('- торг')
        ->and($settings['refusal'])->toBe('Отвечаю только про магазин.')
        // Пустого раздела в промпте быть не должно: заголовок без текста
        // модель читает как приглашение придумать содержимое.
        ->and($settings)->not->toHaveKey('escalation');
});

it('не пускает в промпт больше правил, чем в него имеет смысл класть', function (): void {
    $rules = array_map(static fn (int $i): string => 'правило '.$i, range(1, AssistantConfig::MAX_RULES + 5));

    expect(assistantConfigWith(['rules' => $rules])->list('rules'))
        ->toHaveCount(AssistantConfig::MAX_RULES);
});

it('на пустое приветствие подставляет заводское', function (): void {
    expect(assistantConfigWith(['greeting' => '   '])->greeting())
        ->toBe(AssistantConfig::defaultGreeting());
});

it('имя бота по умолчанию берёт название магазина', function (): void {
    expect(assistantConfigWith()->botName())->toBe('Консультант Интертулер');

    config()->set('settings.assistant', []);
    expect(assistantConfigWith(['bot_name' => '  Тулик  '])->botName())->toBe('Тулик');
});

it('незаданное имя не попадает в промпт и не трогает кэш ответов', function (): void {
    // promptSettings() входит в отпечаток кэша ответов. Пока имя не задано,
    // ключ не должен появляться вовсе — иначе выкат обесценит весь кэш.
    expect(assistantConfigWith()->promptSettings())->not->toHaveKey('bot_name');
});
