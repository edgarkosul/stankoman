<?php

use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseMissing;

test('can delete a page from the table', function () {
    $user = User::factory()->create();
    $page = Page::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListPages::class)
        ->assertCanSeeTableRecords([$page])
        ->callAction(TestAction::make(DeleteAction::class)->table($page))
        ->assertCanNotSeeTableRecords([$page]);

    assertDatabaseMissing($page);
});

test('can bulk delete pages from the table', function () {
    $user = User::factory()->create();
    $pages = Page::factory()->count(3)->create();

    $this->actingAs($user);

    Livewire::test(ListPages::class)
        ->assertCanSeeTableRecords($pages)
        ->selectTableRecords($pages)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertCanNotSeeTableRecords($pages);

    $pages->each(fn (Page $page) => assertDatabaseMissing($page));
});
