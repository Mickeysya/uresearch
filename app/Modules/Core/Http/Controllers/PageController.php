<?php

namespace App\Modules\Core\Http\Controllers;

/**
 * Sidebar destinations that exist as navigation today but have no feature
 * behind them yet. Each renders the same "nothing here yet" shell rather
 * than a 404, so the sidebar is honest about what it links to while the
 * real page is built.
 */
class PageController extends Controller
{
    public function notifications()
    {
        return view('core::pages.placeholder', [
            'title' => 'Notifications',
            'description' => 'A single feed of every alert the portal sends you — decisions on your '
                .'applications, attendance early warnings, and reminders — will appear here.',
        ]);
    }

    public function documents()
    {
        return view('core::pages.placeholder', [
            'title' => 'Documents',
            'description' => 'Every file you have uploaded or been issued, in one place, will appear here. '
                .'For now, documents attached to a specific application can be found on that application\'s tracking page.',
        ]);
    }

    public function calendar()
    {
        return view('core::pages.placeholder', [
            'title' => 'Calendar',
            'description' => 'Upcoming deadlines — RPD milestones, appeal windows, reminders — will appear here.',
        ]);
    }

    public function help()
    {
        return view('core::pages.placeholder', [
            'title' => 'Help and Support',
            'description' => 'Guides and a way to reach CGS support directly will appear here.',
        ]);
    }

    /* -----------------------------------------------------------------
     | CGS-only destinations. Same placeholder convention as above: the
     | sidebar links to them now, they say plainly that they are not built
     | yet, and each names what will replace it.
     |------------------------------------------------------------------*/

    public function cgsAttendanceOverview()
    {
        return view('core::pages.placeholder', [
            'title' => 'Attendance Overview',
            'description' => 'A CGS-wide view of attendance across every postgraduate student — '
                .'cohort averages, the distribution against the 80% threshold, and period-on-period '
                .'movement — will appear here. The per-student figures behind it already exist; what '
                .'is missing is the aggregate. For now, "At-Risk Students" lists everyone currently flagged.',
        ]);
    }

    public function cgsStudentList()
    {
        return view('core::pages.placeholder', [
            'title' => 'Student List',
            'description' => 'Every student with their latest attendance percentage, standing and trend, '
                .'searchable and filterable by department and programme, will appear here. '
                .'Only students currently flagged at-risk are listed today, under "At-Risk Students".',
        ]);
    }

    public function cgsStudents()
    {
        return view('core::pages.placeholder', [
            'title' => 'Students',
            'description' => 'The postgraduate directory — programme, department, supervisor, candidacy '
                .'status and every application a student has filed, in one record. This is the '
                .'"holistic view of a student\'s administrative status" the scope document asks for.',
        ]);
    }

    public function cgsReports()
    {
        return view('core::pages.placeholder', [
            'title' => 'Reports and Analytics',
            'description' => 'Bottleneck analysis — how many applications are sitting at each stage, '
                .'how long they have been there, and where the queue is backing up — plus exportable '
                .'summaries for CGS reporting. Note this overlaps scope documented by other team '
                .'members; agree ownership before building it.',
        ]);
    }

    public function settings()
    {
        return view('core::pages.placeholder', [
            'title' => 'Settings',
            'description' => 'Notification preferences, attendance thresholds and workflow reminder '
                .'timings will be configurable here. They are currently constants in code — '
                .'AttendanceRiskEvaluator::THRESHOLD and the schedule in routes/console.php.',
        ]);
    }

    public function profile()
    {
        return view('core::pages.placeholder', [
            'title' => 'Profile',
            'description' => 'Editing your details and changing your password will be available here.',
        ]);
    }
}
