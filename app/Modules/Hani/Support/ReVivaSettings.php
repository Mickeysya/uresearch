<?php

namespace App\Modules\Hani\Support;

use Illuminate\Support\Facades\DB;

/** Values the Senior Executive can change in the portal; config is the default. */
final class ReVivaSettings
{
    /** Stored key per level: examiner_reminders.master, examiner_reminders.doctoral. */
    public const REMINDERS = 'examiner_reminders';

    /** Before the Master/Doctoral split there was one list; it still counts as Master's. */
    public const LEGACY_REMINDERS = 'external_reminders';

    public const LEVELS = [ReVivaLetters::MASTER => 'Master\'s', ReVivaLetters::DOCTORAL => 'Doctoral'];

    /** @return array<int, int> working days before the deadline, largest first */
    public static function reminderDays(string $level = ReVivaLetters::MASTER): array
    {
        $level = array_key_exists($level, self::LEVELS) ? $level : ReVivaLetters::MASTER;
        $stored = DB::table('re_viva_settings')->where('key', self::REMINDERS.'.'.$level)->value('value');

        if ($stored === null && $level === ReVivaLetters::MASTER) {
            $stored = DB::table('re_viva_settings')->where('key', self::LEGACY_REMINDERS)->value('value');
        }

        $days = $stored !== null ? json_decode($stored, true) : self::configured($level);

        return self::clean((array) $days);
    }

    /** @return array<int, int> the config default for a level */
    public static function configured(string $level): array
    {
        return self::clean((array) config("hani.reviva.examiner_reminders.{$level}", []));
    }

    /** @param array<int, int> $days */
    public static function saveReminderDays(string $level, array $days, int $userId): void
    {
        DB::table('re_viva_settings')->updateOrInsert(['key' => self::REMINDERS.'.'.$level], [
            'value' => json_encode(array_values($days)), 'updated_by_id' => $userId, 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    /** @return array<int, int> */
    protected static function clean(array $days): array
    {
        return collect($days)->map(fn ($d) => (int) $d)->filter(fn ($d) => $d > 0)->unique()->sortDesc()->values()->all();
    }
}
