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

it('registers nightly product search reindex schedule', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduledEvent): bool => Str::contains($scheduledEvent->command, 'products:search-reindex'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 6 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
