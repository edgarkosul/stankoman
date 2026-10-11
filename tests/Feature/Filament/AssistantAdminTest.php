<?php

use App\Enums\SettingType;
use App\Events\CallbackRequests\CallbackRequestSubmitted;
use App\Filament\Pages\AssistantSandbox;
use App\Filament\Pages\AssistantSettings;
use App\Filament\Pages\ChatAppearanceSettings;
use App\Filament\Pages\KbGaps;
use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Filament\Resources\ChatConversations\Pages\ViewChatConversation;
use App\Filament\Resources\KbArticles\KbArticleResource;
use App\Filament\Resources\KbArticles\Pages\CreateKbArticle;
use App\Filament\Resources\KbCategories\KbCategoryResource;
use App\Filament\Widgets\AssistantSpendOverview;
use App\Livewire\Admin\ChatConversationPanel;
use App\Models\CallbackRequest;
use App\Models\Setting;
use App\Models\User;
use App\Shop\Chat\ChatAppearance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Siteko\Chatbot\Ai\AssistantConfig;
use Siteko\Chatbot\Ai\Data\AssistantReply;
use Siteko\Chatbot\Chat\ChatAbuseGuard;
use Siteko\Chatbot\Chat\ChatConversationService;
use Siteko\Chatbot\Kb\Data\KbGapQuestion;
use Siteko\Chatbot\Kb\KbGapAnalyzer;
use Siteko\Chatbot\Kb\KbVectorStore;
use Siteko\Chatbot\Models\ChatConversation;
use Siteko\Chatbot\Models\ChatMessage;

/*
 * Рабочее место администратора бота на sqlite: разделы открываются,
 * оператор перехватывает разговор и отвечает, ответ уходит в «Пробелы»,
 * а оттуда — в форму статьи.
 */

beforeEach(function (): void {
    // Статьи и страницы базы знаний ставят переиндексацию на redis-assistant
    // мимо QUEUE_CONNECTION=sync: без подмены задача уехала бы в живую очередь.
    Queue::fake();

    config(['settings.general.filament_admin_emails' => ['admin@example.com']]);

    $this->admin = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Эдгар']);
});

/**
 * Разговор, где покупатель спросил, а бот позвал человека.
 *
 * @param  list<float>  $vector
 */
function escalatedQuestion(string $question, array $vector): ChatConversation
{
    $conversation = ChatConversation::factory()->escalated()->create();

    ChatMessage::factory()->for($conversation, 'conversation')->create(['body' => $question]);

    ChatMessage::factory()->for($conversation, 'conversation')->fromAssistant()->create([
        'body' => 'Уточню у менеджера и вернусь с ответом.',
        'tool_calls' => [['name' => 'search_knowledge_base', 'arguments' => ['query' => 'оплата по счёту'], 'ms' => 700]],
        'citations' => [['chunk_id' => 'intertooler-page:dostavka-i-oplata#0', 'score' => 0.47, 'title' => 'Доставка и оплата', 'url' => null]],
        'meta' => ['escalated' => true],
        'embedding' => KbVectorStore::packVector($vector),
    ]);

    return $conversation;
}

it('открывает все разделы бота', function (): void {
    $conversation = escalatedQuestion('Можно оплатить по счёту на ООО?', [0.6, 0.8]);

    $this->actingAs($this->admin);

    $this->get(ChatConversationResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Уточню у менеджера');

    // Лента рисуется панелью прямо в странице, не лениво: оператор открывает
    // диалог ради неё, и пустой экран на секунду здесь был бы лишним.
    $this->get(ChatConversationResource::getUrl('view', ['record' => $conversation]))
        ->assertSuccessful()
        ->assertSee('Можно оплатить по счёту на ООО?')
        ->assertSee('искал в базе знаний')
        ->assertSee('Доставка и оплата');

    $this->get(KbGaps::getUrl())->assertSuccessful()->assertSee('Можно оплатить по счёту на ООО?');
    $this->get(AssistantSandbox::getUrl())->assertSuccessful();
    $this->get(AssistantSettings::getUrl())->assertSuccessful()->assertSee('Режим работы');
    $this->get(KbArticleResource::getUrl('index'))->assertSuccessful()->assertSee('Статей пока нет');
    $this->get(KbArticleResource::getUrl('create'))->assertSuccessful();
    $this->get(KbCategoryResource::getUrl('index'))->assertSuccessful();

    expect(ChatConversationResource::getNavigationBadge())->toBe('1');
});

it('не пускает к переписке того, кто не сотрудник', function (): void {
    $conversation = escalatedQuestion('Вопрос', [1.0, 0.0]);

    $this->actingAs(User::factory()->create(['email' => 'buyer@example.com']));

    Livewire::test(ChatConversationPanel::class, ['conversationId' => $conversation->id])
        ->assertForbidden();
});

it('оператор отвечает — разговор переходит к нему, возврат боту снимает пометку', function (): void {
    $conversation = escalatedQuestion('Можно оплатить по счёту на ООО?', [0.6, 0.8]);
    app(ChatConversationService::class)->markPending($conversation);

    $this->actingAs($this->admin);

    Livewire::test(ChatConversationPanel::class, ['conversationId' => $conversation->id])
        ->set('draft', 'Да, выставим счёт — пришлите реквизиты на почту.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '')
        ->assertDispatched('chat-conversation-updated');

    $conversation->refresh();
    $reply = $conversation->messages()->where('role', ChatMessage::ROLE_OPERATOR)->sole();

    expect($conversation->status)->toBe(ChatConversation::STATUS_OPERATOR)
        ->and($conversation->operator_id)->toBe($this->admin->id)
        ->and($conversation->unread_for_visitor)->toBe(1)
        // Бот мог ещё готовить ответ — пометка «печатает» снята сразу.
        ->and(app(ChatConversationService::class)->isPending($conversation))->toBeFalse()
        ->and($reply->body)->toBe('Да, выставим счёт — пришлите реквизиты на почту.');

    Livewire::test(ViewChatConversation::class, ['record' => $conversation->id])
        ->callAction('returnToBot');

    $conversation->refresh();
    $note = $conversation->messages()->where('role', ChatMessage::ROLE_SYSTEM)->latest('id')->first();

    expect($conversation->status)->toBe(ChatConversation::STATUS_BOT)
        ->and($conversation->escalated_at)->toBeNull()
        ->and($conversation->operator_id)->toBeNull()
        // Покупателю — своими словами: «менеджер подключился» больше не правда.
        ->and($note->visitorNote())->toBe('Менеджер передал разговор консультанту.')
        ->and(ChatConversationResource::getNavigationBadge())->toBeNull();
});

it('взятие в работу и закрытие пишут заметки для покупателя', function (): void {
    $conversation = escalatedQuestion('Вопрос', [1.0, 0.0]);

    $this->actingAs($this->admin);

    Livewire::test(ViewChatConversation::class, ['record' => $conversation->id])
        ->callAction('takeOver')
        ->callAction('close');

    $notes = $conversation->messages()->where('role', ChatMessage::ROLE_SYSTEM)->orderBy('id')->get();

    expect($conversation->refresh()->status)->toBe(ChatConversation::STATUS_CLOSED)
        ->and($notes->pluck('meta.event')->all())->toBe(['taken_over', 'closed'])
        // Имя сотрудника остаётся на полях для магазина, покупателю — без имён.
        ->and($notes[0]->body)->toContain('Эдгар')
        ->and($notes[0]->visitorNote())->toBe('К разговору подключился менеджер.')
        ->and($notes[1]->visitorNote())->toBe('Разговор завершён.');
});

it('оператор просит контакты — покупателю приходит та же форма, что показывает бот', function (): void {
    $conversation = escalatedQuestion('Вопрос', [1.0, 0.0]);

    $this->actingAs($this->admin);

    Livewire::test(ChatConversationPanel::class, ['conversationId' => $conversation->id])
        ->call('askForContacts');

    $request = $conversation->messages()->where('role', ChatMessage::ROLE_OPERATOR)->sole();

    expect($request->meta)->toBe(['callback_requested' => true]);
});

it('ответ менеджера «в базу знаний» получает вектор вопроса и приходит в «Пробелы» с материалом', function (): void {
    $conversation = ChatConversation::factory()->operatorLed()->create();
    ChatMessage::factory()->for($conversation, 'conversation')->create(['body' => 'Даёте отсрочку платежа?']);
    $answer = ChatMessage::factory()->for($conversation, 'conversation')->create([
        'role' => ChatMessage::ROLE_OPERATOR,
        'body' => 'Постоянным клиентам — до 30 дней по договору.',
        'operator_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ChatConversationPanel::class, ['conversationId' => $conversation->id])
        ->assertSee('В базу знаний')
        ->call('markForKb', $answer->id)
        ->assertSee('отправлен в «Пробелы»');

    $answer->refresh();

    expect($answer->meta)->toBe(['to_kb' => true])
        // Вектор посчитан при пометке: без него ответ не попал бы ни в одну группу.
        ->and($answer->getRawOriginal('embedding'))->not->toBeNull();

    $report = app(KbGapAnalyzer::class)->report(days: 30, signal: KbGapQuestion::SIGNAL_OPERATOR_ANSWER);

    expect($report->clusters)->toHaveCount(1)
        ->and($report->clusters[0]->title())->toBe('Даёте отсрочку платежа?')
        ->and($report->clusters[0]->answers())->toBe(['Постоянным клиентам — до 30 дней по договору.']);
});

it('«Пробелы» складывают сигналы бота и менеджера об одном и том же в одну группу', function (): void {
    escalatedQuestion('Можно оплатить по счёту на ООО?', [0.6, 0.8]);
    escalatedQuestion('Выставите счёт на организацию?', [0.61, 0.79]);
    $unrelated = escalatedQuestion('Есть ли доставка в Крым?', [0.8, -0.6]);

    // Ответ без сигнала в очередь не попадает вовсе.
    ChatMessage::factory()->for($unrelated, 'conversation')->fromAssistant()->create(['body' => 'Спасибо!']);

    $report = app(KbGapAnalyzer::class)->report();

    expect($report->questionsCount())->toBe(3)
        ->and($report->clusters)->toHaveCount(2)
        ->and($report->clusters[0]->count())->toBe(2)
        ->and($report->signalCounts())->toBe([KbGapQuestion::SIGNAL_ESCALATED => 3]);
});

it('черновик от бота открывает форму статьи с вопросами покупателей', function (): void {
    $conversation = escalatedQuestion('можно оплатить по счёту на ООО?', [0.6, 0.8]);
    $question = $conversation->messages()->where('role', ChatMessage::ROLE_VISITOR)->sole();

    $this->actingAs($this->admin);

    Livewire::test(KbGaps::class)
        ->call('draftArticle', (string) $question->id)
        ->assertRedirect();

    Livewire::withQueryParams(['from' => (string) $question->id])
        ->test(CreateKbArticle::class)
        // Заголовок — из реплики покупателя, приведённой к виду заголовка.
        ->assertSet('data.title', 'Можно оплатить по счёту на ООО')
        ->assertSee('диалог №'.$conversation->id);
});

it('форма статьи не подставляет ответ бота вместо вопроса покупателя', function (): void {
    $conversation = escalatedQuestion('Вопрос покупателя', [1.0, 0.0]);
    $botAnswer = $conversation->messages()->where('role', ChatMessage::ROLE_ASSISTANT)->sole();

    $this->actingAs($this->admin);

    Livewire::withQueryParams(['from' => (string) $botAnswer->id])
        ->test(CreateKbArticle::class)
        ->assertSet('sourceQuestions', []);
});

it('сохраняет настройки бота в настройки пакета и применяет их сразу', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(AssistantSettings::class)
        ->set('data.enabled', false)
        ->set('data.about', 'Продаём станки и оснастку организациям.')
        ->set('data.forbidden_topics', ['торг'])
        ->call('save')
        ->assertHasNoErrors();

    $config = app(AssistantConfig::class);

    // Настройки бота — в `chatbot_settings` пакета, а не в `settings` магазина.
    expect(DB::table('chatbot_settings')->where('site_id', 1)->where('key', 'assistant.enabled')->value('value'))->toBe('false')
        ->and(Setting::query()->where('key', 'assistant.enabled')->exists())->toBeFalse()
        ->and($config->enabled())->toBeFalse()
        ->and($config->disabledByAdmin())->toBeTrue()
        ->and($config->promptSettings())->toMatchArray([
            'about' => 'Продаём станки и оснастку организациям.',
            'forbidden_topics' => '- торг',
        ]);
});

it('сохраняет вид чата: только отличия от умолчания и без старого выключателя робота', function (): void {
    Storage::fake('public');
    $this->actingAs($this->admin);

    // Робота выключали ещё старым выключателем «Лицо бота».
    Setting::query()->create(['key' => 'assistant.mascot', 'value' => '0', 'type' => SettingType::Bool, 'autoload' => true]);
    config(['settings.assistant.mascot' => false]);

    Livewire::test(ChatAppearanceSettings::class)
        // Форма начинается с того, что видно на витрине, — значка.
        ->assertSet('data.launcher_image', 'icon')
        ->set('data.launcher_style', 'pill')
        ->set('data.launcher_image', 'robot')
        ->set('data.launcher_status', 'Отвечу за минуту')
        ->call('save')
        ->assertHasNoErrors();

    expect(json_decode((string) Setting::query()->where('key', 'assistant.launcher')->value('value'), true))
        ->toBe(['style' => 'pill', 'status' => 'Отвечу за минуту'])
        ->and(Setting::query()->where('key', 'assistant.mascot')->exists())->toBeFalse()
        ->and(app(ChatAppearance::class)->launcher()->image)->toBe('robot');

    // Вернули прежнюю кнопку — строки нет вовсе: умолчание живёт в коде.
    Livewire::test(ChatAppearanceSettings::class)
        ->set('data.launcher_style', 'circle')
        ->set('data.launcher_status', null)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'assistant.launcher')->exists())->toBeFalse()
        ->and(app(ChatAppearance::class)->launcher()->isDefault())->toBeTrue();
});

it('предпросмотр вида чата ничего не пишет', function (): void {
    $this->actingAs($this->admin);

    $html = Livewire::test(ChatAppearanceSettings::class)
        ->set('data.launcher_image', 'icon')
        ->set('data.launcher_effect', 'shine')
        ->instance()
        ->previewDocument();

    expect($html)->toContain('chat-launcher cl-fx-shine')
        ->and(Setting::query()->where('key', 'like', 'assistant.launcher%')->exists())->toBeFalse();
});

it('показывает промпт по несохранённой форме', function (): void {
    $this->actingAs($this->admin);

    $page = Livewire::test(AssistantSettings::class)
        ->set('data.about', 'Черновая строка о магазине, ещё не сохранённая.')
        ->mountAction('preview')
        ->assertHasNoErrors()
        ->instance();

    // Содержимое модалки в HTML теста не рисуется, поэтому спрашиваем сам
    // сборщик — тот, что модалка и показывает.
    $prompt = (fn (): string => $this->previewPrompt())->call($page);

    expect($prompt)->toContain('Черновая строка о магазине, ещё не сохранённая.')
        ->and(Setting::query()->where('key', 'assistant.about')->exists())->toBeFalse();
});

it('заводит заявку по контактам из переписки без письма самому себе', function (): void {
    Event::fake([CallbackRequestSubmitted::class]);

    $conversation = escalatedQuestion('Мой номер 8 999 111-22-33, перезвоните', [1.0, 0.0]);

    $this->actingAs($this->admin);

    Livewire::test(ViewChatConversation::class, ['record' => $conversation->id])
        ->callAction('createLead', data: [
            'name' => 'Иван',
            'phone' => '8 999 111-22-33',
            'email' => '',
            'comments' => 'Из чата',
        ])
        ->assertHasNoActionErrors();

    $lead = CallbackRequest::query()->sole();

    expect($lead->phone)->toBe('+79991112233')
        ->and($lead->source)->toBe(CallbackRequest::SOURCE_CHAT)
        ->and($lead->notified_at)->not->toBeNull()
        ->and($conversation->refresh()->lead_ref)->toBe($lead->id);

    Event::assertNotDispatched(CallbackRequestSubmitted::class);
});

it('требует хотя бы один контакт для заявки', function (): void {
    $conversation = escalatedQuestion('Вопрос', [1.0, 0.0]);

    $this->actingAs($this->admin);

    Livewire::test(ViewChatConversation::class, ['record' => $conversation->id])
        ->callAction('createLead', data: ['name' => 'Иван', 'phone' => '', 'email' => ''])
        ->assertHasActionErrors(['phone', 'email']);

    expect(CallbackRequest::query()->exists())->toBeFalse();
});

it('виджет расхода показывает выбранный дневной потолок', function (): void {
    /*
     * Исчерпанный потолок — единственное состояние бота, которое снаружи
     * выглядит как поломка: он молчит, а вопросы уходят менеджеру. Кроме
     * этой плитки объяснения нет нигде.
     */
    config([
        'chatbot.gateway.key' => '',
        'chatbot.chat.abuse.daily_tokens' => 1000,
    ]);
    app()->forgetInstance(ChatAbuseGuard::class);
    app(ChatAbuseGuard::class)->recordSpend(1000);

    $this->actingAs($this->admin);

    Livewire::withoutLazyLoading()
        ->test(AssistantSpendOverview::class)
        ->assertSee('Потолок на сегодня')
        ->assertSee('Потолок выбран');
});

it('виджет расхода считает по расходной книге и честно говорит о бюджете без ключа', function (): void {
    config(['chatbot.gateway.key' => '']);

    $conversation = ChatConversation::factory()->create();
    app(ChatConversationService::class)->addAssistantMessage(
        $conversation,
        'Ответ',
        reply: new AssistantReply(text: 'Ответ', stopReason: 'stop', costRub: 0.35),
    );

    $this->actingAs($this->admin);

    Livewire::withoutLazyLoading()
        ->test(AssistantSpendOverview::class)
        ->assertSee('1 диалог')
        ->assertSee('0,35 ₽')
        ->assertSee('не задан');
});
