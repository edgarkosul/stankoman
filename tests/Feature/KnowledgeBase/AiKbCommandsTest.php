<?php

use App\Models\Page;
use App\Models\User;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\ChatResult;
use App\Services\Ai\Data\EmbeddingBatch;
use App\Services\Ai\Providers\FakeLlmClient;
use App\Shop\PageKbSource;
use App\Shop\SettingsKbSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    // Ключ шлюза из .env виден и тестам, а доктор спрашивает у шлюза его настройки.
    // В обычном прогоне в сеть не ходим — отвечаем за шлюз сами.
    Http::preventStrayRequests();
    Http::fake([
        '*/aitunnel/key' => Http::response(['name' => 'test', 'budget' => null, 'pii' => ['mode' => 'mask', 'types' => null]]),
    ]);

    Page::factory()->create([
        'slug' => 'dostavka-i-oplata',
        'title' => 'Доставка и оплата',
        'content' => '<p>Везём по всей России, срок 3–5 дней.</p>',
        'is_published' => true,
    ]);
});

$chunks = fn (string $source): int => DB::table('kb_chunks')->where('source', $source)->count();

it('переиндексирует страницы и реквизиты', function () use ($chunks): void {
    $this->artisan('ai:kb-reindex')
        ->expectsOutputToContain(PageKbSource::NAME)
        ->expectsOutputToContain(SettingsKbSource::NAME)
        ->assertSuccessful();

    expect($chunks(PageKbSource::NAME))->toBe(1)
        ->and($chunks(SettingsKbSource::NAME))->toBeGreaterThan(0);
});

it('в сухом прогоне ничего не пишет', function (): void {
    $this->artisan('ai:kb-reindex', ['--dry-run' => true])
        ->expectsOutputToContain('Сухой прогон')
        ->assertSuccessful();

    expect(DB::table('kb_chunks')->count())->toBe(0);
});

it('с --prune снимает страницу, выпавшую из белого списка', function () use ($chunks): void {
    $this->artisan('ai:kb-reindex')->assertSuccessful();

    config(['ai_support.knowledge_base.static_pages' => ['kontakty']]);
    app()->forgetInstance(PageKbSource::class);

    $this->artisan('ai:kb-reindex', ['--prune' => true, '--source' => [PageKbSource::NAME]])->assertSuccessful();

    expect($chunks(PageKbSource::NAME))->toBe(0)
        ->and($chunks(SettingsKbSource::NAME))->toBeGreaterThan(0);
});

it('находит фрагменты и показывает ссылку на страницу', function (): void {
    $this->artisan('ai:kb-reindex')->assertSuccessful();

    $this->artisan('ai:kb-search', ['query' => ['какая', 'доставка']])
        ->expectsOutputToContain(route('page.show', ['page' => 'dostavka-i-oplata']))
        ->assertSuccessful();
});

it('удаляет фрагменты одного источника, не трогая остальные', function () use ($chunks): void {
    $this->artisan('ai:kb-reindex')->assertSuccessful();

    $this->artisan('ai:kb-forget', ['source' => PageKbSource::NAME, '--force' => true])->assertSuccessful();

    expect($chunks(PageKbSource::NAME))->toBe(0)
        ->and($chunks(SettingsKbSource::NAME))->toBeGreaterThan(0);
});

it('доктор ругается на пустую базу', function (): void {
    $this->artisan('ai:kb-doctor')
        ->expectsOutputToContain('База знаний пуста')
        ->assertFailed();
});

it('доктор считает источник без статей пустым, а не сломанным', function (): void {
    $this->artisan('ai:kb-reindex')->assertSuccessful();

    // Получатели эскалации — часть готовности: без них сигнал «в чате ждут
    // человека» уходит в никуда, и доктор обязан назвать это поломкой.
    config(['settings.general.filament_admin_emails' => ['admin@intertooler.test']]);
    User::factory()->create(['email' => 'admin@intertooler.test']);

    $this->artisan('ai:kb-doctor')
        ->expectsOutputToContain('Источник «intertooler-kb» пуст')
        ->expectsOutputToContain('Ассистент готов к работе')
        ->assertSuccessful();
});

it('доктор ловит настройку, в которой некому получить эскалацию', function (): void {
    $this->artisan('ai:kb-reindex')->assertSuccessful();

    // Почта в настройке есть, пользователя с ней нет — уведомление
    // в колокольчике уходит в никуда, и ошибки не будет нигде.
    config(['settings.general.filament_admin_emails' => ['admin@intertooler.test']]);

    $this->artisan('ai:kb-doctor')
        ->expectsOutputToContain('уведомления в админке уйдут в никуда')
        ->assertFailed();
});

/**
 * Шлюз для пробы доктора: отдаёт заданный ответ и помнит потолок max_tokens.
 */
function probeLlm(string $reply): LlmClient
{
    return new class(new FakeLlmClient(32), $reply) implements LlmClient
    {
        public ?int $maxTokens = null;

        public function __construct(private readonly FakeLlmClient $inner, private readonly string $reply) {}

        public function chat(string $system, array $messages, array $tools = [], ?int $maxTokens = null, ?string $sessionId = null, ?string $toolChoice = null): ChatResult
        {
            $this->maxTokens = $maxTokens;

            return new ChatResult($this->reply, finishReason: $this->reply === '' ? 'length' : 'stop', outputTokens: 16);
        }

        public function embed(array $texts, string $mode = 'doc'): EmbeddingBatch
        {
            return $this->inner->embed($texts, $mode);
        }

        public function chatModel(): string
        {
            return $this->inner->chatModel();
        }

        public function embeddingModel(): string
        {
            return $this->inner->embeddingModel();
        }

        public function embeddingDimensions(): int
        {
            return $this->inner->embeddingDimensions();
        }
    };
}

it('проба доктора даёт рассуждающей модели потолок в сотни токенов', function (): void {
    app()->instance(LlmClient::class, $llm = probeLlm('ок'));

    $this->artisan('ai:kb-doctor', ['--probe' => true])
        ->expectsOutputToContain('Диалог: «ок»');

    // При 16 рассуждение съедало потолок целиком, и текста не оставалось.
    expect($llm->maxTokens)->toBeGreaterThanOrEqual(256);
});

it('пустой ответ пробы — провал, а не успех', function (): void {
    app()->instance(LlmClient::class, probeLlm(''));

    $this->artisan('ai:kb-doctor', ['--probe' => true])
        ->expectsOutputToContain('Диалог: модель вернула пустой ответ')
        ->doesntExpectOutputToContain('Диалог: «»')
        ->assertFailed();
});
