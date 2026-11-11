<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Support\ReVivaCorrections;
use App\Modules\Hani\Support\ReVivaDocuments;
use App\Modules\Hani\Support\ReVivaLogging;
use App\Modules\Hani\Support\ReVivaProcess;
use App\Modules\Hani\Support\ReVivaWindow;
use Illuminate\Http\Request;

/**
 * The student's re-viva: forms, the three uploads, returns, status and
 * result. Never shows an examiner's name on a page; only the downloadable
 * blank forms name the panel.
 */
class ReVivaStudentController extends Controller
{
    public const FIELDS = [
        'confirmation' => ReVivaLogging::DOC_CONFIRMATION,
        'summary' => ReVivaLogging::DOC_SUMMARY,
        'thesis' => ReVivaLogging::DOC_THESIS,
    ];

    public function mine(Request $request)
    {
        $user = $request->user();
        $rounds = ReVivaDetail::with(['application.documents', 'documentReturns'])
            ->whereHas('application', fn ($q) => $q->where('module_type', 're_viva')->where('student_id', $user->id))
            ->orderByDesc('cycle_number')->get();

        abort_if($rounds->isEmpty() && ! ReVivaWindow::categoryFourViva($user), 403);

        return view('hani::re_viva.mine', [
            'rounds' => $rounds, 'expecting' => $this->expecting($user),
            'forms' => ReVivaDocuments::FORMS, 'fields' => self::FIELDS,
        ]);
    }

    public function form(Request $request, string $form, string $variant, ReVivaDocuments $documents)
    {
        abort_unless(isset(ReVivaDocuments::FORMS[$form]), 404);
        abort_if($variant === 'blank' && ! ReVivaWindow::categoryFourViva($request->user()) && ! ReVivaLogging::latestCycle($request->user()->id), 403);

        return response($documents->form($form, $variant, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$form.'-'.$variant.'.pdf"',
        ]);
    }

    public function submit(Request $request, ReVivaLogging $logging, ReVivaProcess $process)
    {
        $user = $request->user();
        abort_unless($this->expecting($user), 403, 'There is no re-viva submission expected from you at the moment.');

        $rule = ['required', 'file', 'mimes:pdf', 'max:'.$this->maxKb()];
        $request->validate(array_fill_keys(array_keys(self::FIELDS), $rule));

        $result = $logging->log($user, collect(self::FIELDS)->mapWithKeys(fn ($type, $field) => [$type => $request->file($field)])->all());
        $process->submitted($result['detail']);

        $redirect = redirect()->route('reviva.mine')->with('status', 'Submitted — being checked by CGS.');

        return $result['warning'] ? $redirect->with('warning', $result['warning']) : $redirect;
    }

    /** Only the returned documents are required again. */
    public function resubmit(Request $request, ReVivaDetail $detail, ReVivaProcess $process)
    {
        abort_unless($detail->application->module_type === 're_viva' && $detail->application->student_id === $request->user()->id, 404);
        abort_unless($detail->application->status === Application::STATUS_RETURNED, 403);

        $returned = (array) $detail->documentReturns()->first()?->documents;
        $fields = array_intersect(self::FIELDS, $returned);
        $request->validate(array_fill_keys(array_keys($fields), ['required', 'file', 'mimes:pdf', 'max:'.$this->maxKb()]));

        $process->resubmit($detail, collect($fields)->mapWithKeys(fn ($type, $field) => [$type => $request->file($field)])->all());

        return redirect()->route('reviva.mine')->with('status', 'Resubmitted — being checked by CGS.');
    }

    public function submitFinalThesis(Request $request, ReVivaDetail $detail, ReVivaCorrections $corrections)
    {
        abort_unless($detail->application->module_type === 're_viva' && $detail->application->student_id === $request->user()->id, 404);
        $request->validate(['final_thesis' => ['required', 'file', 'mimes:pdf', 'max:'.$this->maxKb()]]);
        $corrections->submit($detail, $request->file('final_thesis'), $request->user());

        return redirect()->route('reviva.mine')->with('status', 'Final thesis submitted. CGS has been told.');
    }

    protected function expecting(User $student): ?ReVivaWindow
    {
        $latest = ReVivaLogging::latestCycle($student->id);

        return ReVivaLogging::blockReason($latest) === null ? ReVivaWindow::for($student, $latest) : null;
    }

    protected function maxKb(): int
    {
        return (int) config('hani.reviva.upload_max_mb', 20) * 1024;
    }
}
