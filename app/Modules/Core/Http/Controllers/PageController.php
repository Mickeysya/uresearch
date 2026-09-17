<?php

namespace App\Modules\Core\Http\Controllers;

/**
 * Sidebar destinations that exist as navigation today but have no feature
 * behind them yet. Each renders the same "nothing here yet" shell rather
 * than a 404, so the sidebar is honest about what it links to while the
 * real page is built.
 *
 * All six were separate methods returning the same view with different
 * strings. They are one array now: adding a placeholder is an entry here
 * plus a route line, and replacing one with a real screen is deleting both.
 * The routes keep their own paths, names and middleware -- the CGS four sit
 * behind a role gate the other two must not inherit.
 */
class PageController extends Controller
{
    /** @var array<string, array{0: string, 1: string}> key => [title, description] */
    private const PAGES = [
        'help' => [
            'Help and Support',
            'Guides and a way to reach CGS support directly will appear here.',
        ],
        'settings' => [
            'Settings',
            'Notification preferences, attendance thresholds and workflow reminder '
                .'timings will be configurable here. They are currently constants in code: '
                .'AttendanceRiskEvaluator::THRESHOLD and the schedule in routes/console.php.',
        ],
        'cgs-attendance' => [
            'Attendance Overview',
            'A CGS-wide view of attendance across every postgraduate student will appear here: '
                .'cohort averages, the distribution against the 80% threshold, and period-on-period '
                .'movement. The per-student figures behind it already exist; what '
                .'is missing is the aggregate. For now, "At-Risk Students" lists everyone currently flagged.',
        ],
        'cgs-student-list' => [
            'Student List',
            'Every student with their latest attendance percentage, standing and trend, '
                .'searchable and filterable by department and programme, will appear here. '
                .'Only students currently flagged at-risk are listed today, under "At-Risk Students".',
        ],
        'cgs-students' => [
            'Students',
            'The postgraduate directory, holding programme, department, supervisor, candidacy '
                .'status and every application a student has filed, in one record. This is the '
                .'"holistic view of a student\'s administrative status" the scope document asks for.',
        ],
        'cgs-reports' => [
            'Reports and Analytics',
            'Bottleneck analysis: how many applications are sitting at each stage, '
                .'how long they have been there, and where the queue is backing up, plus exportable '
                .'summaries for CGS reporting. Note this overlaps scope documented by other team '
                .'members; agree ownership before building it.',
        ],
    ];

    /** $page comes from the route's ->defaults('page', ...), not the URL. */
    public function show(string $page)
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        [$title, $description] = self::PAGES[$page];

        return view('core::pages.placeholder', compact('title', 'description'));
    }
}
