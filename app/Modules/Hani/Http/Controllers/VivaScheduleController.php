<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Notifications\VivaScheduled;
use App\Modules\Hani\Support\ExaminerDivergence;
use App\Modules\Hani\Support\VivaInvite;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Step 8 of the viva flow: the Academic Executive finalises the date and
 * sends the calendar. Not a workflow stage: setting a date changes no
 * status, and a date can be moved. Only the AE of the student's own
 * department, the same scoping the engine applies to their queues.
 */
class VivaScheduleController extends Controller
{
    public function index(Request $request)
    {
        $notices = Application::query()
            ->where('module_type', VivaProgress::NOTICE)
            ->where('status', Application::STATUS_APPROVED)
            ->whereHas('student', fn ($q) => $q->where('department', $request->user()->department))
            ->with('student')
            ->orderBy('submitted_at')
            ->get();

        $progress = VivaProgress::forNotices($notices);

        // Scheduled, then ready, then waiting: the AE's next job is the middle group.
        $groups = $progress->groupBy(fn (VivaProgress $p) => match (true) {
            (bool) $p->detail?->isScheduled() => 'scheduled',
            $p->isReadyToSchedule() => 'ready',
            default => 'waiting',
        });

        return view('hani::viva.schedule_index', [
            'ready' => $groups->get('ready', collect()),
            'scheduled' => $groups->get('scheduled', collect())->sortBy(fn ($p) => $p->detail->viva_at),
            'waiting' => $groups->get('waiting', collect()),
            'divergence' => ExaminerDivergence::forNotices($notices->pluck('id')),
        ]);
    }

    public function edit(Request $request, Application $application)
    {
        $progress = $this->progressFor($request, $application);

        if (! $progress->isReadyToSchedule()) {
            return redirect()->route('viva.schedule.index')
                ->with('error', 'This viva cannot be scheduled yet: '.implode('; ', $progress->waitingFor()).'.');
        }

        return view('hani::viva.schedule_form', [
            // The co-supervisor the student named on Form #019, as a starting
            // point for the AE; the AE's own entry wins once saved.
            'coSupervisorEmail' => $progress->detail->co_supervisor_email
                ?? VivaSoftboundDetail::where('application_id', $progress->softbound?->id)->value('co_supervisor_email'),
            'progress' => $progress,
            'panel' => $progress->panel(),
            'detail' => $progress->detail,
            'recipients' => VivaInvite::recipients($application, $progress->detail, $progress->panel()),
            'divergence' => ExaminerDivergence::forNotices([$application->id])[$application->id] ?? null,
        ]);
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        $progress = $this->progressFor($request, $application);

        // The guideline's rule, server-side: both the nomination approved and
        // the softbound in before a date is set.
        if (! $progress->isReadyToSchedule()) {
            return redirect()->route('viva.schedule.index')
                ->with('error', 'This viva cannot be scheduled yet: '.implode('; ', $progress->waitingFor()).'.');
        }

        $data = $request->validate([
            'viva_date' => ['required', 'date', 'after_or_equal:today'],
            'viva_time' => ['required', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'chair_name' => ['required', 'string', 'max:150'],
            'chair_email' => ['required', 'email', 'max:150'],
            'co_supervisor_name' => ['nullable', 'string', 'max:150', 'required_with:co_supervisor_email'],
            'co_supervisor_email' => ['nullable', 'email', 'max:150', 'required_with:co_supervisor_name'],
        ], [
            'viva_date.after_or_equal' => 'The viva date cannot be in the past.',
            'co_supervisor_name.required_with' => 'Give the co-supervisor\'s name as well as their email.',
            'co_supervisor_email.required_with' => 'Give the co-supervisor\'s email so they receive the invitation.',
        ]);

        $detail = $progress->detail;
        $rescheduled = $detail->isScheduled();

        $detail->fill([
            'viva_at' => $data['viva_date'].' '.$data['viva_time'],
            'venue' => $data['venue'],
            'chair_name' => $data['chair_name'],
            'chair_email' => $data['chair_email'],
            'co_supervisor_name' => $data['co_supervisor_name'] ?? null,
            'co_supervisor_email' => $data['co_supervisor_email'] ?? null,
            'scheduled_by_id' => $request->user()->id,
            'schedule_sequence' => $rescheduled ? $detail->schedule_sequence + 1 : 0,
        ])->save();

        activity('viva')
            ->causedBy($request->user())
            ->performedOn($application)
            ->withProperties(['action' => $rescheduled ? 'rescheduled' : 'scheduled', 'viva_at' => (string) $detail->viva_at])
            ->log($rescheduled ? 'Viva rescheduled' : 'Viva scheduled');

        $sent = $this->sendInvites($application, $detail, $progress, $rescheduled);

        return redirect()->route('viva.schedule.index')->with('status',
            ($rescheduled ? 'Viva rescheduled' : 'Viva scheduled').' for '.$application->student->name
            .' on '.$detail->viva_at->format('j M Y, g:i a').'. Invitation sent to '.$sent.' '.str('recipient')->plural($sent).'.');
    }

    /** Best effort, after the save: the date stands even if the mail server is down. */
    protected function sendInvites(Application $notice, VivaDetail $detail, VivaProgress $progress, bool $rescheduled): int
    {
        $recipients = VivaInvite::recipients($notice, $detail, $progress->panel());
        $discuss = isset(ExaminerDivergence::forNotices([$notice->id])[$notice->id]);
        $notification = new VivaScheduled($notice, $detail, $rescheduled, $discuss);

        try {
            Notification::send($recipients['users'], $notification);

            foreach ($recipients['emails'] as $email => $name) {
                Notification::route('mail', [$email => $name])->notify($notification);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return count($recipients['users']) + count($recipients['emails']);
    }

    /** The viva record behind a notice, scoped to the AE's own department. */
    protected function progressFor(Request $request, Application $application): VivaProgress
    {
        abort_unless($application->module_type === VivaProgress::NOTICE, 404);
        abort_unless($application->student?->department === $request->user()->department, 403,
            'That student is not in your department.');

        return VivaProgress::forNotices(collect([$application]))->first();
    }
}
