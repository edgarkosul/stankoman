<?php

use App\Filament\Pages\SiteExports;
use App\Jobs\GenerateMarketFeedJob;
use App\Jobs\GenerateSitemapFilesJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('site exports page metadata and route are configured', function (): void {
    expect(SiteExports::getNavigationGroup())->toBe('Экспорт/Импорт');
    expect(SiteExports::getNavigationLabel())->toBe('SEO и фиды');
    expect(SiteExports::getNavigationIcon())->toBe('heroicon-o-globe-alt');

    $defaults = (new ReflectionClass(SiteExports::class))->getDefaultProperties();

    expect($defaults['view'])->toBe('filament.pages.site-exports');
    expect($defaults['title'])->toBe('SEO файлы и фиды сайта');
    expect($defaults['slug'])->toBe('site-exports');
    expect(Route::has('filament.admin.pages.site-exports'))->toBeTrue();
});

it('site exports page renders content wrappers with div containers', function (): void {
    $html = Livewire::test(SiteExports::class)->html();

    expect($html)->toContain('<div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">')
        ->not->toContain('<section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm');
});

it('site exports page queues sitemap generation for current user', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    config()->set('settings.general.filament_admin_emails', [$user->email]);
    $this->actingAs($user);

    Livewire::actingAs($user)
        ->test(SiteExports::class)
        ->call('queueSitemapGeneration');

    Queue::assertPushed(GenerateSitemapFilesJob::class, function (GenerateSitemapFilesJob $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->afterCommit === true;
    });
});

it('site exports page queues market feed generation for current user', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    config()->set('settings.general.filament_admin_emails', [$user->email]);
    $this->actingAs($user);

    Livewire::actingAs($user)
        ->test(SiteExports::class)
        ->call('queueMarketFeedGeneration');

    Queue::assertPushed(GenerateMarketFeedJob::class, function (GenerateMarketFeedJob $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->afterCommit === true;
    });
});
