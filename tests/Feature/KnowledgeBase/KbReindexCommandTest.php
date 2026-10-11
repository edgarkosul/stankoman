<?php

use App\Models\Page;
use App\Shop\PageKbSource;
use App\Shop\SettingsKbSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Переиндексация базы знаний — команда пакета (`chatbot:kb-reindex`), источники —
 * магазина: страницы из белого списка и реквизиты из настроек. Здесь проверяется
 * стык: пакет находит наши источники по тегу и снимает выпавшую страницу.
 * Поиск, удаление источника и доктор — команды пакета, их тесты в bots.
 */

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();

    Page::factory()->create([
        'slug' => 'dostavka-i-oplata',
        'title' => 'Доставка и оплата',
        'content' => '<p>Везём по всей России, срок 3–5 дней.</p>',
        'is_published' => true,
    ]);
});

$chunks = fn (string $source): int => DB::table('kb_chunks')->where('source', $source)->count();

it('переиндексирует страницы и реквизиты магазина', function () use ($chunks): void {
    $this->artisan('chatbot:kb-reindex')
        ->expectsOutputToContain(PageKbSource::NAME)
        ->expectsOutputToContain(SettingsKbSource::NAME)
        ->assertSuccessful();

    expect($chunks(PageKbSource::NAME))->toBe(1)
        ->and($chunks(SettingsKbSource::NAME))->toBeGreaterThan(0)
        // Один сайт: всё лежит под номером 1.
        ->and(DB::table('kb_chunks')->where('site_id', '!=', 1)->count())->toBe(0);
});

it('в сухом прогоне ничего не пишет', function (): void {
    $this->artisan('chatbot:kb-reindex', ['--dry-run' => true])
        ->expectsOutputToContain('Сухой прогон')
        ->assertSuccessful();

    expect(DB::table('kb_chunks')->count())->toBe(0);
});

it('с --prune снимает страницу, выпавшую из белого списка', function () use ($chunks): void {
    $this->artisan('chatbot:kb-reindex')->assertSuccessful();

    config(['ai_support.knowledge_base.static_pages' => ['kontakty']]);
    app()->forgetInstance(PageKbSource::class);

    $this->artisan('chatbot:kb-reindex', ['--prune' => true, '--source' => [PageKbSource::NAME]])->assertSuccessful();

    expect($chunks(PageKbSource::NAME))->toBe(0)
        ->and($chunks(SettingsKbSource::NAME))->toBeGreaterThan(0);
});
