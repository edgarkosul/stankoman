<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('registers sitemap generation schedule', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduledEvent): bool => Str::contains($scheduledEvent->command, 'seo:generate-sitemap'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('registers market feed generation schedule', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduledEvent): bool => Str::contains($scheduledEvent->command, 'feeds:generate-market'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('40 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
