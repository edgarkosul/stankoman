<?php

use App\Models\Page;

it('renders a yandex-compatible favicon link on the home page', function (): void {
    Page::factory()->create([
        'slug' => 'home',
        'title' => 'Главная',
        'content' => '<p>Главная страница</p>',
        'is_published' => true,
    ]);

    $response = $this->get(route('home'));

    $response->assertSuccessful()
        ->assertSee(
            '<link rel="icon" href="'.asset('favicon.ico').'" type="image/x-icon">',
            false,
        )
        ->assertSee(
            '<link rel="shortcut icon" href="'.asset('favicon.ico').'">',
            false,
        )
        ->assertSee('<link rel="manifest" href="/site.webmanifest">', false)
        ->assertDontSee('<link rel="icon" href="/favicon.ico" sizes="any">', false);

    expect(substr_count($response->getContent(), 'type="image/x-icon"'))->toBe(1);
});
