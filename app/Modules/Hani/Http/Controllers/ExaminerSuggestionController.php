<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Support\ConflictOfInterest;
use App\Modules\Hani\Support\Matching\ExaminerSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Suggested examiners" as JSON, for the nomination form and the replace-a-
 * seat flow. The page asks again whenever the candidate or the title changes.
 *
 * Staff only, and scoped: a supervisor for their own candidates, an Academic
 * Executive for their own department, the Senior Executive for anyone.
 * Students are refused by the route's role list and never reach here --
 * examiner names, scores and recommendations are never shown to a candidate.
 */
class ExaminerSuggestionController extends Controller
{
    public function __invoke(Request $request, ExaminerSuggestions $suggestions): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::STUDENT)],
            'title' => ['nullable', 'string', 'max:255'],
            'exclude' => ['nullable', 'array', 'max:4'],
            'exclude.*' => ['integer'],
        ]);

        $student = User::findOrFail($data['student_id']);
        $this->authoriseFor($request->user(), $student);

        if (class_exists(ConflictOfInterest::class)) {
            $coi = app(ConflictOfInterest::class);
            $suggestions->withFlags(fn ($examiner, $s) => $coi->flagsFor($examiner, $s));
        }

        return response()->json($suggestions->forStudent($student, $data['title'] ?? null, $data['exclude'] ?? []));
    }

    protected function authoriseFor(User $user, User $student): void
    {
        $allowed = match ($user->role) {
            Role::SUPERVISOR => $student->supervisor_id === $user->id,
            Role::ACADEMIC_EXEC => filled($user->department) && $user->department === $student->department,
            Role::SENIOR_EXEC_CGS => true,
            default => false,
        };

        abort_unless($allowed, 403);
    }
}
