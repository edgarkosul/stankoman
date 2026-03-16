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

    private function resolveEmailListField(?string $key = null): ?string
    {
        $settingKey = $key ?? $this->getRecord()->key;

        return self::EMAIL_LIST_KEYS[$settingKey] ?? null;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $field = $this->resolveEmailListField($data['key'] ?? null);

        if ($field === null) {
            return $data;
        }

        $decoded = json_decode($data['value'] ?? '[]', true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $data[$field] = collect($decoded)
            ->map(fn (string $email): array => ['email' => $email])
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $field = $this->resolveEmailListField();

        if ($field === null) {
            return $data;
        }

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

    protected function afterSave(): void
    {
        Setting::flushCache();
    }
}
