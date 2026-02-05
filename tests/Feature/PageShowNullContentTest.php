<?php

use App\Models\Page;

it('renders published page when content is null', function () {
    $page = Page::factory()->create([
        'is_published' => true,
        'content' => null,
    ]);

    $this->get(route('page.show', ['page' => $page->slug]))
        ->assertSuccessful()
        ->assertSee($page->title)
        ->assertSee('static-page')
        ->assertSee('fi-prose');
});
