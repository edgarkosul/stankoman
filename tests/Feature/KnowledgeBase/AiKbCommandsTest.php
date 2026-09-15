<?php

use App\Models\Page;
use App\Shop\PageKbSource;
use App\Shop\SettingsKbSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

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

    $this->artisan('ai:kb-doctor')
        ->expectsOutputToContain('Источник «intertooler-kb» пуст')
        ->expectsOutputToContain('Ассистент готов к работе')
        ->assertSuccessful();
});
