<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Enums\SettingType;
use App\Filament\Resources\Settings\Schemas\SettingForm;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Setting;
use App\Support\WorkSchedule;
use Filament\Resources\Pages\EditRecord;

class EditSetting extends EditRecord
{
    protected static string $resource = SettingResource::class;

    private const EMAIL_LIST_KEYS = [
        'general.manager_emails' => 'manager_emails',
        'general.filament_admin_emails' => 'filament_admin_emails',
    ];

    private const EMAIL_VALUE_KEYS = [
        'company.public_email' => 'email_value',
        'mail.from.address' => 'email_value',
        'company.legal_name' => 'legal_name_value',
        'company.brand_line' => 'brand_line_value',
        'company.site_host' => 'site_host_value',
        'company.inn' => 'inn_value',
        'company.ogrn' => 'ogrn_value',
        'company.ogrnip' => 'ogrnip_value',
        'company.phone' => 'phone_value',
        'company.site_url' => 'site_url_value',
        'company.legal_addr' => 'legal_addr_value',
        'company.correspondence_addr' => 'correspondence_addr_value',
        'company.bank.name' => 'bank_name_value',
        'company.bank.bik' => 'bank_bik_value',
        'company.bank.rs' => 'bank_rs_value',
        'company.bank.ks' => 'bank_ks_value',
    ];

    private const BOOL_VALUE_KEYS = [
        'product.show_callback_button' => 'bool_value',
    ];

    /**
     * @param  array<string, string>  $map
     */
    private function resolveCustomField(array $map, ?string $key = null): ?string
    {
        $settingKey = $key ?? $this->getRecord()->key;

        return $map[$settingKey] ?? null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (($data['key'] ?? null) === SettingForm::WORK_SCHEDULE_KEY) {
            $schedule = WorkSchedule::fromArray(json_decode((string) ($data['value'] ?? ''), true));

            $data['work_schedule_days'] = collect($schedule->days)
                ->map(fn (?array $hours, int $day): array => [
                    'day' => $day,
                    'open' => $hours !== null,
                    'from' => $hours[0] ?? null,
                    'to' => $hours[1] ?? null,
                ])
                ->values()
                ->all();
            $data['work_schedule_note'] = $schedule->note;

            return $data;
        }

        $field = $this->resolveCustomField(self::EMAIL_LIST_KEYS, $data['key'] ?? null);

        if ($field !== null) {
            $decoded = json_decode($data['value'] ?? '[]', true);

            if (! is_array($decoded)) {
                $decoded = [];
            }

            $data[$field] = collect($decoded)
                ->map(fn (string $email): array => ['email' => $email])
                ->all();

            return $data;
        }

        $boolValueField = $this->resolveCustomField(self::BOOL_VALUE_KEYS, $data['key'] ?? null);

        if ($boolValueField !== null) {
            $data[$boolValueField] = filter_var($data['value'] ?? null, FILTER_VALIDATE_BOOL);

            return $data;
        }

        $emailValueField = $this->resolveCustomField(self::EMAIL_VALUE_KEYS, $data['key'] ?? null);

        if ($emailValueField !== null) {
            $data[$emailValueField] = trim((string) ($data['value'] ?? ''));
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->getRecord()->key === SettingForm::WORK_SCHEDULE_KEY) {
            $days = [];

            foreach ($data['work_schedule_days'] ?? [] as $row) {
                $days[(int) ($row['day'] ?? 0)] = ($row['open'] ?? false)
                    ? [(string) ($row['from'] ?? ''), (string) ($row['to'] ?? '')]
                    : null;
            }

            // Через WorkSchedule, а не как пришло из формы: поле времени отдаёт
            // «09:00:00», а сайт и чат читают «09:00».
            $schedule = WorkSchedule::fromArray([
                'days' => $days,
                'note' => (string) ($data['work_schedule_note'] ?? ''),
            ]);

            $data['type'] = SettingType::Json->value;
            $data['value'] = json_encode($schedule->toArray(), JSON_UNESCAPED_UNICODE);

            unset($data['work_schedule_days'], $data['work_schedule_note']);

            return $data;
        }

        $field = $this->resolveCustomField(self::EMAIL_LIST_KEYS);

        if ($field !== null) {
            $emails = collect($data[$field] ?? [])
                ->pluck('email')
                ->map(fn ($email): string => trim((string) $email))
                ->filter()
                ->values()
                ->all();

            $data['type'] = SettingType::Json->value;
            $data['value'] = json_encode($emails, JSON_UNESCAPED_UNICODE);

            unset($data[$field]);

            return $data;
        }

        $boolValueField = $this->resolveCustomField(self::BOOL_VALUE_KEYS);

        if ($boolValueField !== null) {
            $data['type'] = SettingType::Bool->value;
            $data['value'] = ($data[$boolValueField] ?? false) ? '1' : '0';

            unset($data[$boolValueField]);

            return $data;
        }

        $emailValueField = $this->resolveCustomField(self::EMAIL_VALUE_KEYS);

        if ($emailValueField !== null) {
            $data['type'] = SettingType::String->value;
            $data['value'] = trim((string) ($data[$emailValueField] ?? ''));

            unset($data[$emailValueField]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        Setting::flushCache();
    }
}
