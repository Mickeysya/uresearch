<?php

/*
 * READ-ONLY. Run through `php artisan tinker --execute` by record.sh right
 * after `nureen:demo-fresh`: prints what that command created, as one JSON
 * line prefixed "DEMOINFO:", so the recorder knows each application's id,
 * reference, student and who acts at each stage without guessing from the
 * printed table. Writes nothing.
 */

use App\Modules\Core\Contracts\SuppliesApprovedGaApplication;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\DemoRecord;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;

$person = fn (?User $u) => $u ? ['name' => $u->name, 'email' => $u->email, 'matric' => $u->matric_no, 'programme' => $u->programme, 'department' => $u->department] : null;

$actor = function (Application $a, $stage) {
    $student = $a->student;
    $first = fn (string $role, ?string $dept = null) => User::where('role', $role)->when($dept, fn ($q) => $q->where('department', $dept))->orderBy('id')->first();

    return match (true) {
        $stage->role === Role::SUPERVISOR => User::find(match ($a->module_type) {
            'supervision' => SupervisionDetail::where('application_id', $a->id)->value('requested_supervisor_id'),
            'ga_extension' => GaExtensionDetail::where('application_id', $a->id)->value('supervisor_id'),
            'ga_certification' => GaCertificationDetail::where('application_id', $a->id)->value('supervisor_id'),
            default => $student->supervisor_id,
        }),
        $stage->role === Role::NON_EXEC_CGS => app(CgsAssignment::class)->officer($a->module_type) ?? $first(Role::NON_EXEC_CGS),
        Role::isDepartmentScoped($stage->role) => $first($stage->role, $student->department),
        default => $first($stage->role),
    };
};

$engine = app(WorkflowEngine::class);
$apps = Application::whereIn('id', DemoRecord::idsFor('applications'))
    ->where('module_type', '!=', 'ga_application')->orderBy('id')->get();

$out = ['applications' => [], 'attendance' => []];

foreach ($apps as $a) {
    $chain = [];
    foreach ($a->module()->stages($a) as $stage) {
        $chain[] = ['key' => $stage->key, 'label' => $stage->label, 'login' => $actor($a, $stage)?->email];
    }

    $row = [
        'id' => $a->id,
        'reference' => $a->reference(),
        'module' => $a->module_type,
        'status' => $a->status,
        'stage' => $a->status === Application::STATUS_PENDING ? $a->current_stage : null,
        'student' => $person($a->student) + ['supervisor_email' => $a->student->supervisor?->email, 'supervisor_name' => $a->student->supervisor?->name],
        'chain' => $chain,
    ];

    if ($a->module_type === 'ga_extension' && app()->bound(SuppliesApprovedGaApplication::class)) {
        $approved = app(SuppliesApprovedGaApplication::class)->approvedFor($a->student);
        $row['ga_service_end'] = $approved['service_end_date']?->toDateString();
    }

    if ($a->module_type === 'ga_certification') {
        $row['certificate_reference'] = GaCertificationDetail::where('application_id', $a->id)->value('reference_number');
    }

    $out['applications'][] = $row;
}

$students = AttendanceRecord::whereIn('id', DemoRecord::idsFor('attendance_records'))->distinct()->pluck('student_id');
foreach ($students as $id) {
    $state = AttendanceRiskState::where('student_id', $id)->first();
    $out['attendance'][] = ['student' => $person(User::find($id)), 'at_risk' => (bool) $state?->at_risk, 'reason' => $state?->reason,
        'current' => $state?->current_percentage, 'projected' => $state?->projected_percentage];
}

$out['staff'] = [
    'admin' => User::where('role', Role::ADMIN)->orderBy('id')->value('email'),
    'cgs' => app(CgsAssignment::class)->officer('ga_extension')?->email,
    'director' => User::where('role', Role::SENIOR_DIRECTOR_CGS)->orderBy('id')->value('email'),
];

echo 'DEMOINFO:'.json_encode($out).PHP_EOL;
