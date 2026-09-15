<?php

use App\Enums\SettingType;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Setting;
use App\Models\User;
use App\Support\WorkSchedule;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['settings.general.filament_admin_emails' => ['admin@example.com']]);

    $this->actingAs(User::factory()->create(['email' => 'admin@example.com']));

    // Строку заводит миграция — фабрика упёрлась бы в уникальный ключ.
    $this->setting = Setting::query()->where('key', 'company.work_schedule')->sole();

    // Ключи строк повторителя — числа, а не uuid: иначе строку не адресовать.
    $this->undoRepeaterFake = Repeater::fake();
});

afterEach(function (): void {
    ($this->undoRepeaterFake)();
});

/**
 * @return list<array{day: int, open: bool, from: ?string, to: ?string}>
 */
function scheduleRows(array $hours): array
{
    return collect(range(1, 7))
        ->map(fn (int $day): array => [
            'day' => $day,
            'open' => isset($hours[$day]),
            'from' => $hours[$day][0] ?? null,
            'to' => $hours[$day][1] ?? null,
        ])
        ->all();
}

it('заводится миграцией с тем, что сайт обещал раньше', function (): void {
    $schedule = WorkSchedule::fromArray($this->setting->getValueForConfig());

    expect($this->setting->type)->toBe(SettingType::Json)
        ->and($schedule->lines())->toBe(['Пн – Пт: 9:00 – 18:00', 'Сб – Вс: выходной'])
        ->and($schedule->note)->toBe('В субботу и воскресенье — отгрузка по предварительной договорённости.');
});

it('в списке настроек показывается так же, как на сайте', function (): void {
    $this->get(SettingResource::getUrl('index', panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Пн – Пт: 9:00 – 18:00, Сб – Вс: выходной');
});

it('сохраняется из формы по дням недели вместе с примечанием', function (): void {
    Livewire::test(EditSetting::class, ['record' => $this->setting->getRouteKey()])
        ->assertFormFieldIsHidden('value')
        ->assertFormSet(['work_schedule_note' => 'В субботу и воскресенье — отгрузка по предварительной договорённости.'])
        ->fillForm([
            'work_schedule_days' => scheduleRows([
                1 => ['08:00', '17:00'],
                2 => ['08:00', '17:00'],
                3 => ['08:00', '17:00'],
                4 => ['08:00', '17:00'],
                5 => ['08:00', '16:00'],
                6 => ['10:00', '14:00'],
            ]),
            'work_schedule_note' => 'Отгрузка по звонку',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->setting->refresh();
    $schedule = WorkSchedule::fromArray($this->setting->getValueForConfig());

    expect($this->setting->type)->toBe(SettingType::Json)
        ->and($schedule->days[1])->toBe(['08:00', '17:00'])
        ->and($schedule->lines())->toBe(['Пн – Чт: 8:00 – 17:00', 'Пт: 8:00 – 16:00', 'Сб: 10:00 – 14:00', 'Вс: выходной'])
        ->and($schedule->note)->toBe('Отгрузка по звонку');
});

it('не сохраняет рабочий день без часов', function (): void {
    $rows = scheduleRows([1 => ['09:00', '18:00']]);
    $rows[1] = ['day' => 2, 'open' => true, 'from' => null, 'to' => null];

    Livewire::test(EditSetting::class, ['record' => $this->setting->getRouteKey()])
        ->fillForm(['work_schedule_days' => $rows])
        ->call('save')
        ->assertHasFormErrors(['work_schedule_days.1.from' => 'required', 'work_schedule_days.1.to' => 'required']);
});
