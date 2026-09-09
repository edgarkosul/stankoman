<?php

use App\Support\Products\ProductSearchSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('runs product search reindex command with explicit chunk size', function (): void {
    $searchSync = Mockery::mock(ProductSearchSync::class);
    $searchSync->shouldReceive('rebuildIndex')
        ->once()
        ->with(123)
        ->andReturn([
            'indexed' => 42,
        ]);

    app()->instance(ProductSearchSync::class, $searchSync);

    $this->artisan('products:search-reindex', [
        '--chunk' => 123,
        '--skip-settings' => true,
    ])
        ->expectsOutputToContain('Rebuilding product search index with chunk size 123')
        ->expectsOutput('Product search reindex completed. Indexed: 42.')
        ->assertSuccessful();
});

it('runs the nightly reconciliation instead of a full rebuild', function (): void {
    $events = collect(app(Schedule::class)->events());

    /*
     * Полная пересборка начинается с removeAllFromSearch(): пока она идёт,
     * поиск на сайте отдаёт пустоту. По расписанию её быть не должно —
     * ночью работает сверка, а пересборка осталась ручной командой.
     */
    expect($events->first(fn ($event): bool => Str::contains($event->command, 'products:search-reindex')))
        ->toBeNull();

    $audit = $events->first(fn ($event): bool => Str::contains($event->command, 'search:audit'));

    expect($audit)->not->toBeNull()
        ->and($audit->expression)->toBe('45 4 * * *')
        ->and($audit->withoutOverlapping)->toBeTrue();

    $settings = $events->first(fn ($event): bool => Str::contains($event->command, 'scout:sync-index-settings'));

    expect($settings)->not->toBeNull()
        ->and($settings->expression)->toBe('40 4 * * *');
});
