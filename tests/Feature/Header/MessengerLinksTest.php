<?php

use App\Models\Page;
use App\Support\MessengerLinks;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    config(['company.phone' => '+7 (900) 246-86-60']);
});

it('шапка и подвал ведут в MAX и Telegram по ссылкам из настроек', function (): void {
    config([
        'company.max_url' => 'https://max.ru/u/profile-token',
        'company.telegram_url' => 'https://t.me/intertooler',
    ]);

    $page = Page::factory()->create(['is_published' => true, 'content' => '<p>Текст</p>']);

    $html = $this->get(route('page.show', ['page' => $page->slug]))
        ->assertSuccessful()
        ->getContent();

    expect(substr_count($html, 'href="https://max.ru/u/profile-token"'))->toBe(2)
        ->and(preg_match_all('#href="https://t\.me/intertooler"\s+target="_blank"#', $html))->toBe(2)
        ->and($html)->not->toContain('href="https://max.ru/"')
        ->and($html)->not->toContain('tg://resolve');
});

it('без ссылки на профиль значка MAX нет, а Telegram ведёт по номеру', function (): void {
    config([
        'company.max_url' => '',
        'company.telegram_url' => '',
    ]);

    $page = Page::factory()->create(['is_published' => true, 'content' => '<p>Текст</p>']);

    $html = $this->get(route('page.show', ['page' => $page->slug]))
        ->assertSuccessful()
        ->getContent();

    expect($html)->not->toContain('max.ru')
        ->and(substr_count($html, 'href="tg://resolve?phone=79002468660"'))->toBe(2)
        ->and(preg_match('#href="tg://[^"]*"\s+target=#', $html))->toBe(0);
});

it('без телефона и ссылки значка Telegram нет', function (): void {
    config([
        'company.phone' => '',
        'company.telegram_url' => '',
    ]);

    expect(MessengerLinks::fromConfig()->telegram)->toBeNull();
});
