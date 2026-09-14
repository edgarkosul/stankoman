<?php

use App\Filament\Resources\CallbackRequests\CallbackRequestResource;
use App\Filament\Resources\CallbackRequests\Pages\ListCallbackRequests;
use App\Models\CallbackRequest;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['settings.general.filament_admin_emails' => ['admin@example.com']]);

    $this->admin = User::factory()->create(['email' => 'admin@example.com']);
});

it('lists pending callback requests and opens one', function (): void {
    $pending = CallbackRequest::factory()->create([
        'name' => 'Ждущий Покупатель',
        'phone' => '+79990001122',
        'comments' => 'Нужна консультация по пиле',
    ]);
    CallbackRequest::factory()->create([
        'name' => 'Уже Обзвонённый',
        'status' => CallbackRequest::STATUS_CALLED,
    ]);

    $this->actingAs($this->admin)
        ->get(CallbackRequestResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Ждущий Покупатель')
        ->assertDontSee('Уже Обзвонённый');

    $this->actingAs($this->admin)
        ->get(CallbackRequestResource::getUrl('view', ['record' => $pending], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('+79990001122')
        ->assertSee('Нужна консультация по пиле')
        ->assertSee('не отправлено');

    expect(CallbackRequestResource::getNavigationBadge())->toBe('1');
});

it('marks a request as contacted or cancelled from the table', function (): void {
    [$contacted, $cancelled] = CallbackRequest::factory()->count(2)->create();

    $this->actingAs($this->admin);

    Livewire::test(ListCallbackRequests::class)
        ->callTableAction('markContacted', $contacted)
        ->callTableAction('cancel', $cancelled);

    expect($contacted->refresh()->status)->toBe(CallbackRequest::STATUS_CALLED)
        ->and($cancelled->refresh()->status)->toBe(CallbackRequest::STATUS_CANCELLED)
        ->and(CallbackRequestResource::getNavigationBadge())->toBeNull();
});
