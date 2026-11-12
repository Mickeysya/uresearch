<?php

namespace App\Modules\Norhanis\Support\Demo;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Support\ClaimablePublications;
use App\Modules\Norhanis\Support\StageDurations;

/**
 * What the demo data looks like once seeded, for norhanis:demo-seed to
 * print: counts by module, status and stage; by department; what waits on
 * each approver role, and which supervisors and Chairs have nothing; the
 * urgent and stalled rows; the per-stage medians the report charts; and
 * the totals the CGS Applications overview shows.
 *
 * Reads the whole of Travel, Publication and Claims (not only the demo
 * rows), because that is what the dashboards show.
 *
 * @return array<int, string> lines
 */
final class DemoReport
{
    protected const MODULES = ['travel', 'publication', 'claims_student'];

    /** @return array<int, string> */
    public static function lines(): array
    {
        $registry = app(ModuleRegistry::class);
        $lines = [];
        $apps = Application::whereIn('module_type', self::MODULES)->with('student')->get();

        $lines[] = '== By module, status and stage (all Travel / Publication / Claims rows) ==';
        foreach (self::MODULES as $key) {
            $mine = $apps->where('module_type', $key);
            $lines[] = sprintf('%s: %d', $registry->get($key)->label(), $mine->count());
            foreach ($mine->groupBy('status') as $status => $rows) {
                $stages = $status === Application::STATUS_PENDING
                    ? ' ('.$rows->groupBy('current_stage')->map->count()->map(fn ($n, $s) => "{$s} {$n}")->implode(', ').')'
                    : '';
                $lines[] = "   {$status}: {$rows->count()}{$stages}";
            }
        }

        $lines[] = '';
        $lines[] = '== By department ==';
        foreach ($apps->groupBy(fn ($a) => $a->student?->department ?? '(none)')->sortKeys() as $department => $rows) {
            $lines[] = sprintf('   %-48s %3d  (travel %d, publication %d, claims %d)', $department, $rows->count(),
                $rows->where('module_type', 'travel')->count(), $rows->where('module_type', 'publication')->count(), $rows->where('module_type', 'claims_student')->count());
        }

        $pending = $apps->where('status', Application::STATUS_PENDING);
        $lines[] = '';
        $lines[] = '== Waiting for each approver role ==';
        $byRole = [];
        foreach ($pending as $application) {
            $byRole[$application->currentStage()?->role ?? '?'][] = $application;
        }
        foreach ($byRole as $role => $rows) {
            $lines[] = sprintf('   %-22s %d', Role::label($role), count($rows));
        }

        $supervisors = User::where('role', Role::SUPERVISOR)->get();
        $withNone = $supervisors->filter(fn (User $s) => ! $pending->contains(fn ($a) => $a->current_stage === 'supervisor' && $a->student?->supervisor_id === $s->id));
        $lines[] = sprintf('   Supervisors with a row in their queue: %d of %d', $supervisors->count() - $withNone->count(), $supervisors->count());
        $lines[] = '   Supervisors with none: '.($withNone->pluck('email')->implode(', ') ?: 'none');

        $chairs = User::where('role', Role::CHAIR)->get();
        $chairsNone = $chairs->filter(fn (User $c) => ! $pending->contains(fn ($a) => $a->current_stage === 'chair' && $a->student?->department === $c->department));
        $lines[] = sprintf('   Chairs with a row in their queue: %d of %d', $chairs->count() - $chairsNone->count(), $chairs->count());
        $lines[] = '   Chairs with none: '.($chairsNone->pluck('department')->implode(', ') ?: 'none');

        $durations = app(StageDurations::class);
        $urgent = 0;
        $stalled = 0;
        foreach ($pending as $application) {
            $module = $application->module();
            if ($module->decisionIsOverdue($application)) {
                $stalled++;
            }
            if ($application->module_type === 'travel' && ($estimate = $durations->estimate($application))
                && ($detail = TravelDetail::where('application_id', $application->id)->first())
                && $detail->travel_start_date->lte($estimate['expected']->copy()->startOfDay())) {
                $urgent++;
            }
        }
        $lines[] = '';
        $lines[] = "== Urgent travel (departs before its expected decision): {$urgent}";
        $lines[] = "== Waiting past the stage's charter (stalled-reminder candidates): {$stalled}";

        $lines[] = '';
        $lines[] = '== Median working days per stage (the report\'s "time per stage") ==';
        foreach (self::MODULES as $key) {
            $parts = [];
            foreach ($registry->get($key)->stages() as $stage) {
                $typical = $durations->typical($key, $stage->key);
                $parts[] = sprintf('%s %s (charter %s)', $stage->key, $typical['days'] ?? '—', $durations->charterDays($key, $stage->key) ?? '—');
            }
            $lines[] = '   '.$registry->get($key)->label().': '.implode(' · ', $parts);
        }

        $longest = $pending->sortBy('submitted_at')->take(5);
        $lines[] = '';
        $lines[] = '== Waiting longest ==';
        foreach ($longest as $application) {
            $lines[] = sprintf('   #%d %s at %s since %s', $application->id, $application->module()->label(), $application->current_stage, $application->submitted_at?->format('j M Y'));
        }

        $lines[] = '';
        $lines[] = '== CGS Applications overview totals ==';
        foreach (self::MODULES as $key) {
            $rows = $apps->where('module_type', $key)->where('status', '!=', Application::STATUS_DRAFT);
            $lines[] = sprintf('   %s: total %d · pending %d · approved %d · rejected %d', $registry->get($key)->label(), $rows->count(),
                $rows->where('status', Application::STATUS_PENDING)->count(), $rows->where('status', Application::STATUS_APPROVED)->count(), $rows->where('status', Application::STATUS_REJECTED)->count());
        }

        $claimable = User::where('role', Role::STUDENT)->get()->sum(fn (User $s) => app(ClaimablePublications::class)->for($s)->count());
        $lines[] = "== Approved Pay and Claims journals still claimable: {$claimable}";

        return $lines;
    }
}
