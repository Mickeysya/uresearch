<?php

namespace App\Modules\Norhanis\Support\Demo;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What norhanis:demo-seed created, in storage/app/demo-seed-manifest.json,
 * so norhanis:demo-clear removes exactly that and nothing else: the
 * applications (with their details, history, drafts, sign-offs, reminders
 * and documents, which go with them), the files on disk, the in-app
 * notifications, and the activity-log rows about them.
 */
final class DemoManifest
{
    public static function path(): string
    {
        return storage_path('app/demo-seed-manifest.json');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** @return array{created_at?: string, applications: array<string, array<int, int>>, notifications: array<int, string>} */
    public static function read(): array
    {
        if (! self::exists()) {
            return ['applications' => [], 'notifications' => []];
        }

        return json_decode((string) file_get_contents(self::path()), true) ?: ['applications' => [], 'notifications' => []];
    }

    /** @param  array<string, array<int, int>>  $applications  module_type => ids */
    public static function write(array $applications, array $notifications): void
    {
        file_put_contents(self::path(), json_encode([
            'created_at' => now()->toIso8601String(),
            'note' => 'Written by php artisan norhanis:demo-seed. norhanis:demo-clear deletes exactly these.',
            'applications' => $applications,
            'notifications' => array_values($notifications),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Delete everything the manifest lists. Claims first: they point at
     * publications.
     *
     * @return array<string, int> what was removed
     */
    public static function clear(): array
    {
        $manifest = self::read();
        $order = ['claims_student', 'travel', 'publication'];
        $ids = [];
        foreach ($order as $module) {
            $ids[$module] = array_map('intval', $manifest['applications'][$module] ?? []);
        }
        $all = array_merge(...array_values($ids));

        $documents = ApplicationDocument::whereIn('application_id', $all)->get(['id', 'path', 'application_id']);
        $removed = ['applications' => 0, 'documents' => $documents->count(), 'notifications' => 0, 'activity' => 0];

        DB::transaction(function () use ($ids, $all, $documents, $manifest, &$removed) {
            if (DB::getSchemaBuilder()->hasTable('activity_log')) {
                $removed['activity'] = DB::table('activity_log')
                    ->where(fn ($q) => $q
                        ->where(fn ($a) => $a->where('subject_type', Application::class)->whereIn('subject_id', $all))
                        ->orWhere(fn ($d) => $d->where('subject_type', ApplicationDocument::class)->whereIn('subject_id', $documents->pluck('id'))))
                    ->delete();
            }

            $removed['notifications'] = DB::table('notifications')->whereIn('id', $manifest['notifications'] ?? [])->delete();

            foreach ($ids as $module => $list) {
                // Only rows that are still this module's: a manifest is never
                // allowed to delete anything else.
                $removed['applications'] += Application::where('module_type', $module)->whereIn('id', $list)->get()
                    ->each->delete()->count();
            }
        });

        foreach ($documents as $document) {
            Storage::disk('local')->delete($document->path);
        }
        foreach ($all as $id) {
            if (Storage::disk('local')->exists("applications/{$id}") && Storage::disk('local')->files("applications/{$id}") === []) {
                Storage::disk('local')->deleteDirectory("applications/{$id}");
            }
        }

        @unlink(self::path());

        return $removed;
    }
}
