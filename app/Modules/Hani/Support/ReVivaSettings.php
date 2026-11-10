<?php

namespace App\Modules\Hani\Support;

use Illuminate\Support\Facades\DB;

/** Values the Senior Executive can change in the portal; config is the default. */
final class ReVivaSettings
{
    public const REMINDERS = 'external_reminders';

    /** @return array<int, int> working days before the deadline, largest first */
    public static function reminderDays(): array
    {
        $stored = DB::table('re_viva_settings')->where('key', self::REMINDERS)->value('value');
        $days = $stored !== null ? json_decode($stored, true) : config('hani.reviva.external_reminders', [10, 3]);

        return collect((array) $days)->map(fn ($d) => (int) $d)->filter(fn ($d) => $d > 0)->unique()->sortDesc()->values()->all();
    }

    /** @param array<int, int> $days */
    public static function saveReminderDays(array $days, int $userId): void
    {
        DB::table('re_viva_settings')->updateOrInsert(['key' => self::REMINDERS], [
            'value' => json_encode(array_values($days)), 'updated_by_id' => $userId, 'updated_at' => now(), 'created_at' => now(),
        ]);
    }
}
