<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pending applications that NO account can act on, for CGS's warning list:
 *
 *   - at a 'supervisor' stage, where the supervisor named on the request is
 *     missing or no longer has the Supervisor role (GA Extension,
 *     Certification, Supervision);
 *   - a Supervision request at its 'chair' stage, where the student's
 *     department has no Chair account (the Chair's queue is
 *     department-scoped, so nobody else sees it).
 *
 * GA Extension and Certification can be REASSIGNED to another Supervisor
 * account: the detail row's supervisor_id changes, approval_history gets a
 * 'reassigned' row with CGS's reason (shown to the student on the details
 * page; it restarts that stage's charter clock; ProcessingTimeAnalytics does
 * not count it as a decision), and the new supervisor is emailed. A
 * Supervision request is not reassignable -- its named supervisor IS the
 * appointment being asked for -- so CGS rejects it or fixes the account.
 *
 * Never writes applications.status or current_stage (rule 1): the
 * application stays exactly where it is, only who can act on it changes.
 */
class StuckSupervisorStages
{
    public const REASSIGNED = 'reassigned';

    /** @return Collection<int, array{application: Application, stage: string, problem: string, reassignable: bool}> */
    public function all(): Collection
    {
        $stuck = collect();

        $supervisorStage = Application::query()
            ->whereIn('module_type', ['ga_extension', 'ga_certification', 'supervision'])
            ->where('status', Application::STATUS_PENDING)
            ->where('current_stage', 'supervisor')
            ->with('student')
            ->orderBy('submitted_at')
            ->get();

        $named = $this->namedSupervisors($supervisorStage);
        $people = User::whereIn('id', array_filter($named))->get()->keyBy('id');

        foreach ($supervisorStage as $application) {
            $id = $named[$application->id] ?? null;
            $person = $id ? $people->get($id) : null;

            $problem = match (true) {
                ! $id => 'No supervisor is named on this request.',
                ! $person => 'The supervisor named on this request no longer has an account.',
                $person->role !== Role::SUPERVISOR => "{$person->name} ({$person->email}) no longer has the Supervisor role.",
                default => null,
            };

            if ($problem) {
                $stuck->push([
                    'application' => $application,
                    'stage' => 'Lecturer/Supervisor',
                    'problem' => $problem,
                    'reassignable' => $application->module_type !== 'supervision',
                ]);
            }
        }

        $chairDepartments = User::where('role', Role::CHAIR)->pluck('department')->filter()->unique();

        Application::query()
            ->where('module_type', 'supervision')
            ->where('status', Application::STATUS_PENDING)
            ->where('current_stage', 'chair')
            ->with('student')
            ->orderBy('submitted_at')
            ->get()
            ->reject(fn (Application $a) => $chairDepartments->contains($a->student?->department))
            ->each(fn (Application $a) => $stuck->push([
                'application' => $a,
                'stage' => 'Chair of Department',
                'problem' => 'There is no Chair account for '.($a->student?->department ?: 'the student’s department').'. Create one in Users and Roles.',
                'reassignable' => false,
            ]));

        return $stuck;
    }

    /** Hand a stuck GA Extension or Certification to another Supervisor account, with a reason. */
    public function reassign(Application $application, User $to, User $by, string $reason): void
    {
        $detail = match ($application->module_type) {
            'ga_extension' => GaExtensionDetail::where('application_id', $application->id)->firstOrFail(),
            'ga_certification' => GaCertificationDetail::where('application_id', $application->id)->firstOrFail(),
            default => throw new \InvalidArgumentException('Only GA Extension and Certification requests can be reassigned.'),
        };

        $from = $detail->supervisor_id ? User::find($detail->supervisor_id) : null;

        DB::transaction(function () use ($application, $detail, $to, $by, $reason, $from) {
            $detail->update(['supervisor_id' => $to->id]);

            ApprovalHistory::create([
                'application_id' => $application->id,
                'approver_id' => $by->id,
                'stage_key' => 'supervisor',
                'stage_label' => 'Non-Executive CGS',
                'decision' => self::REASSIGNED,
                'remarks' => 'Supervisor changed from '.($from?->name ?? 'nobody').' to '.$to->name.'. '.$reason,
            ]);
        });

        app(SubmissionNotifier::class)->notify($application->fresh(), $reason);
    }

    /** @return array<int, ?int> application id => the supervisor named on its request */
    protected function namedSupervisors(Collection $applications): array
    {
        $ids = $applications->groupBy('module_type')->map->pluck('id');

        return GaExtensionDetail::whereIn('application_id', $ids['ga_extension'] ?? [])->pluck('supervisor_id', 'application_id')->all()
            + GaCertificationDetail::whereIn('application_id', $ids['ga_certification'] ?? [])->pluck('supervisor_id', 'application_id')->all()
            + SupervisionDetail::whereIn('application_id', $ids['supervision'] ?? [])->pluck('requested_supervisor_id', 'application_id')->all();
    }
}
