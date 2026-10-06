<?php

namespace App\Modules\Core\Services;

/**
 * The Chair of Department dashboard.
 *
 * Everything about "what is on my desk" is in ApproverDashboard, which the
 * Supervisor and generic Approver screens share. The Chair used to have one
 * thing beyond that -- filing examiner panels -- which is why this class
 * existed on its own; that nomination ability has since been removed, and
 * the recent-decisions panel it left behind (chair.blade.php's aside row)
 * now reads from the parent class's own myRecentDecisions(), same as every
 * other approver.
 *
 * WHY A CHAIR STILL HAS ITS OWN SCREEN. The generic approver dashboard
 * builds one stat card per queue, and a Chair owns five stages, so it
 * rendered six cards of which five normally read zero, plus a bar chart of
 * five categories with one bar in it. None of it told a Chair the one thing
 * they are actually measured on: whether anything has been sitting on their
 * desk too long. chair-stat-cards.blade.php and chair.blade.php answer that
 * instead; this class is what stays empty until it needs to differ from
 * ApproverDashboard again.
 */
class ChairDashboard extends ApproverDashboard
{
}
