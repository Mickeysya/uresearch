<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\Concerns\ReadsAttendance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

/**
 * The signed-in user's own record: what the portal holds about them, the one
 * field they may change themselves, and their password.
 *
 * WHAT IS EDITABLE HERE, AND WHY SO LITTLE.
 *
 * Name, email, matric number, programme, department, faculty, role and
 * supervisor are all *administrative facts*, not preferences. A student who
 * could edit their own programme could move themselves onto a different
 * candidacy deadline; one who could edit `supervisor_id` could reassign their
 * own supervisor and land their applications in a stranger's approval queue --
 * that column is set by CGS approving a Supervision request, and it is the
 * only thing that makes an appointment real.
 *
 * So this screen shows those and lets the user change a contact number and
 * their password. Everything else is a request to CGS, which is how the paper
 * process works too. When the administrator's Users and Roles screen is built
 * (docs/scope/jason.md 5.5), that is where the rest gets edited, by someone
 * entitled to.
 */
class ProfileController extends Controller
{
    /** attendanceSource() — whichever module supplies attendance, or null. */
    use ReadsAttendance;

    public function show(Request $request)
    {
        $user = $request->user();

        return view('core::profile.show', [
            'user' => $user,
            'supervisor' => $user->supervisor,
            'signIns' => $this->recentSignIns($user->id),
            'stats' => $this->stats($user),
        ]);
    }

    /**
     * The figures on the identity card.
     *
     * A profile card that is only a name and an avatar leaves the reader
     * asking "and?" — these answer it with things the portal already knows,
     * rather than inventing a bio field nobody will fill in.
     *
     * Each entry is [label, value, tone]; a null value is dropped by the
     * view, so a supervisor simply has fewer figures than a student rather
     * than a row of dashes.
     *
     * @return \Illuminate\Support\Collection<int, array{label: string, value: string, tone: string}>
     */
    protected function stats($user)
    {
        $stats = collect();

        if ($user->isStudent()) {
            $open = Application::where('student_id', $user->id)
                ->where('status', Application::STATUS_PENDING)
                ->count();

            $total = Application::where('student_id', $user->id)->count();

            $stats->push(['label' => 'Applications', 'value' => (string) $total, 'tone' => 'plain']);
            $stats->push([
                'label' => 'In Progress',
                'value' => (string) $open,
                'tone' => $open > 0 ? 'info' : 'plain',
            ]);

            // Through the contract, not Nureen's model — Core names no module.
            // Absent module, absent panel, no fatal.
            if ($reading = $this->attendanceSource()?->latestFor($user)) {
                $stats->push([
                    'label' => 'Attendance',
                    'value' => rtrim(rtrim(number_format($reading->percentage, 1), '0'), '.').'%',
                    'tone' => $reading->at_risk ? 'critical' : 'good',
                ]);
            }
        }

        if ($user->supervisees()->exists()) {
            $stats->push([
                'label' => 'Supervisees',
                'value' => (string) $user->supervisees()->count(),
                'tone' => 'plain',
            ]);
        }

        return $stats;
    }

    /**
     * The one detail a user owns outright.
     *
     * Deliberately its own route rather than one big "save profile" form:
     * the page holds two unrelated actions, and a single submit button that
     * sometimes means "change my phone number" and sometimes "change my
     * password" is how people change the wrong one.
     */
    public function updateContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Deliberately loose: Malaysian numbers, international numbers,
            // extensions and the +60 / 0 prefixes people actually type.
            // Rejecting a valid number is worse than storing an odd one.
            'contact_no' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-]+$/'],
        ], [
            'contact_no.regex' => 'A contact number can only contain digits, spaces, and + - ( ).',
        ]);

        $request->user()->update(['contact_no' => $data['contact_no'] ?? null]);

        return back()->with('status', 'Contact details updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            // `current_password` checks against the signed-in user's hash, so
            // someone on a machine left logged in cannot change the password
            // without knowing the old one.
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'current_password.current_password' => 'That is not your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        // 'password' is a hashed cast on User, so this is stored hashed.
        $request->user()->update(['password' => $request->input('password')]);

        // A password change is exactly the kind of event the audit log exists
        // for -- and the one an account's real owner most needs to see if it
        // was not them. The password itself is never in the properties.
        activity('auth')
            ->causedBy($request->user())
            ->withProperties(['action' => 'changed password', 'ip' => $request->ip()])
            ->log('Changed password');

        // Keeps this session valid while invalidating the old session id.
        $request->session()->regenerate();

        return back()->with('status', 'Password changed.');
    }

    /**
     * Recent sign-ins, from the activity log LoginController already writes.
     *
     * Shown because it is the one security signal a user can act on: an
     * unfamiliar time or address is how someone notices an account has been
     * used by somebody else.
     *
     * @return \Illuminate\Support\Collection<int, array{at: \Illuminate\Support\Carbon, ip: ?string, action: string}>
     */
    protected function recentSignIns(int $userId, int $limit = 4)
    {
        try {
            return Activity::query()
                ->where('log_name', 'auth')
                ->where('causer_id', $userId)
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(fn (Activity $row) => [
                    'at' => $row->created_at,
                    'ip' => $row->properties['ip'] ?? null,
                    'action' => $row->properties['action'] ?? 'signed in',
                ]);
        } catch (\Throwable $e) {
            // The panel is a nicety; a missing activity_log table should not
            // cost the user their profile page.
            report($e);

            return collect();
        }
    }
}
