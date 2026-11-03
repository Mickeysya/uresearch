<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Support\ExaminerDashboard;
use Illuminate\Http\Request;

/**
 * Examiner availability at a glance (FYP objective iv). The Academic
 * Executive's own department; the whole university for the Senior Executive,
 * the Senior Director and the Dean (routes.php). Read-only.
 */
class ExaminerDashboardController extends Controller
{
    public function __invoke(Request $request, ExaminerDashboard $dashboard)
    {
        return view('hani::examiner_dashboard.index', $dashboard->for($request->user()));
    }
}
