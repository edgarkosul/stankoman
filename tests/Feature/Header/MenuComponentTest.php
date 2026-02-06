<?php

use App\Models\Menu;
use App\Models\MenuItem;
use App\View\Components\HeaderMenu;

test('header menu component renders', function () {
    $view = $this->component(HeaderMenu::class);

    $view->assertSee('aria-label="Primary"', false);
});

test('header menu collapse toggles on full row click', function () {
    $menu = Menu::factory()->create(['key' => 'primary']);
    $parent = MenuItem::factory()->for($menu)->create([
        'label' => 'Parent Item',
        'type' => 'url',
        'url' => 'https://example.test/parent',
    ]);

    MenuItem::factory()->for($menu)->create([
        'parent_id' => $parent->id,
        'label' => 'Child Item',
        'type' => 'url',
        'url' => 'https://example.test/child',
    ]);

    $view = $this->component(HeaderMenu::class);
    $html = (string) $view;

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query(sprintf('//button[@aria-controls="menu-mobile-%d"]', $parent->id));

    expect($nodes->length)->toBe(1);
    expect(trim($nodes->item(0)->textContent))->toContain($parent->label);
});
