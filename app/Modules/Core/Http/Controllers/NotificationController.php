<?php

namespace App\Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notification feed — every alert the portal has sent this user.
 *
 * Works for every role. The rows are Laravel `DatabaseNotification` records,
 * so a notification appears here simply by listing 'database' in its own
 * via() — this controller never names a notification class.
 *
 * A row's shape is whatever that notification's toArray() stored. Everything
 * read here is optional and falls back, so a notification written by a
 * teammate's module renders sensibly without this file being edited.
 */
class NotificationController extends Controller
{
    /** Rows per page. */
    public const PER_PAGE = 20;

    public function index(Request $request)
    {
        $user = $request->user();
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';

        $notifications = ($filter === 'unread' ? $user->unreadNotifications() : $user->notifications())
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('core::notifications.index', [
            'notifications' => $notifications,
            'filter' => $filter,
            'unreadCount' => $user->unreadNotifications()->count(),
            'totalCount' => $user->notifications()->count(),
        ]);
    }

    /**
     * Mark one notification read, then continue to whatever it was about.
     *
     * POST rather than GET because it changes state. Scoped through the
     * user's own relation, so one person cannot mark another's notification
     * read by guessing a UUID.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $row = $request->user()->notifications()->findOrFail($notification);

        $row->markAsRead();

        return redirect($this->destinationFor($request, $row));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = $request->user()->unreadNotifications()->count();

        $request->user()->unreadNotifications->markAsRead();

        return back()->with('status', $count === 0
            ? 'Nothing was unread.'
            : "Marked {$count} ".\Illuminate\Support\Str::plural('notification', $count).' as read.');
    }

    /**
     * Where following a notification should land.
     *
     * The tracking page is students-only (`role:student` on the route), so an
     * approver following their own notification would hit a 403. They stay on
     * the feed instead — the row is still marked read either way.
     */
    protected function destinationFor(Request $request, $row): string
    {
        $applicationId = $row->data['application_id'] ?? null;

        if ($applicationId && $request->user()->isStudent()) {
            return route('applications.show', $applicationId);
        }

        return url()->previous() ?: route('notifications.index');
    }
}
