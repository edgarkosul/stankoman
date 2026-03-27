<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Enums\SettingType;
use App\Filament\Resources\Settings\SettingResource;
use App\Models\Setting;
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
        'company.phone' => 'phone_value',
        'company.site_url' => 'site_url_value',
        'company.legal_addr' => 'legal_addr_value',
        'company.bank.name' => 'bank_name_value',
        'company.bank.bik' => 'bank_bik_value',
        'company.bank.rs' => 'bank_rs_value',
        'company.bank.ks' => 'bank_ks_value',
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

        $emailValueField = $this->resolveCustomField(self::EMAIL_VALUE_KEYS, $data['key'] ?? null);

        if ($emailValueField !== null) {
            $data[$emailValueField] = trim((string) ($data['value'] ?? ''));
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
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
