<?php

use App\Enums\SettingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $notification = DB::table('settings')
            ->where('key', 'general.notification_email')
            ->first();

        $manager = DB::table('settings')
            ->where('key', 'general.manager_emails')
            ->first();

        $managerEmails = $this->decodeEmailList($manager?->value ?? null);
        $notificationEmail = trim((string) ($notification?->value ?? ''));

        if ($notificationEmail !== '') {
            $managerEmails[] = $notificationEmail;
        }

        $managerEmails = collect($managerEmails)
            ->map(fn ($email): string => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($manager !== null) {
            DB::table('settings')
                ->where('id', $manager->id)
                ->update([
                    'type' => SettingType::Json->value,
                    'value' => json_encode($managerEmails, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        } elseif ($managerEmails !== []) {
            DB::table('settings')->insert([
                'key' => 'general.manager_emails',
                'type' => SettingType::Json->value,
                'value' => json_encode($managerEmails, JSON_UNESCAPED_UNICODE),
                'description' => null,
                'autoload' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')
            ->where('key', 'general.notification_email')
            ->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $manager = DB::table('settings')
            ->where('key', 'general.manager_emails')
            ->first();

        $managerEmails = $this->decodeEmailList($manager?->value ?? null);
        $notificationEmail = array_shift($managerEmails);

        if ($manager !== null) {
            DB::table('settings')
                ->where('id', $manager->id)
                ->update([
                    'type' => SettingType::Json->value,
                    'value' => json_encode(array_values($managerEmails), JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }

        if ($notificationEmail !== null) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'general.notification_email'],
                [
                    'type' => SettingType::String->value,
                    'value' => $notificationEmail,
                    'description' => null,
                    'autoload' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    /**
     * @return list<string>
     */
    private function decodeEmailList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(fn ($email): string => trim((string) $email))
            ->filter()
            ->values()
            ->all();
    }
};
