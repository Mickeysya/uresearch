<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

/**
 * Deciding a page of a queue in one go.
 *
 * Why this is in Core and generic rather than a method per module: the
 * alternative is a `decide-bulk` route in all thirteen `routes.php` files
 * across five people's folders, for behaviour that is identical in every one
 * of them. The module is a route parameter and comes out of the registry, so
 * a module added tomorrow gets this without writing anything.
 *
 * It does NOT bypass the engine. Every row goes through
 * `WorkflowEngine::decide()`, one at a time, which is the only code allowed
 * to write `status` or `current_stage`, and which re-checks the actor's role
 * against the stage that row is actually sitting on. So a signed-in student
 * posting a list of ids decides nothing: every call throws and the result is
 * "0 decided".
 *
 * Deliberately NOT atomic across the batch. Each decide() is its own
 * transaction, and one row that has already been decided by someone else
 * must not roll back the nineteen that were fine. The summary says what
 * happened to each group.
 */
class QueueController extends Controller
{
    /** The most rows one submission may decide. */
    protected const MAX_BATCH = 100;

    public function decideBulk(
        Request $request,
        string $module,
        WorkflowEngine $engine,
        ModuleRegistry $registry
    ): RedirectResponse {
        // An unknown module key is a 404, not a validation error: the route
        // is wrong, not the input.
        abort_unless($registry->has($module), 404);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'ids.*' => ['integer'],
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ], [
            'ids.required' => 'Tick at least one application first.',
            'ids.max' => 'Decide at most '.self::MAX_BATCH.' applications at a time.',
        ]);

        // Scoped to the module in the URL, so a list of ids cannot reach
        // across into another module's rows.
        $applications = Application::whereIn('id', $data['ids'])
            ->where('module_type', $module)
            ->get();

        $decided = 0;
        $refused = 0;

        foreach ($applications as $application) {
            try {
                $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
                $decided++;
            } catch (UnauthorizedException|\LogicException $e) {
                // Not yours to act on, or someone decided it while this page
                // was open. Both are ordinary, and neither stops the rest.
                $refused++;
            }
        }

        // Anything asked for but not found was already filtered out above.
        $refused += count($data['ids']) - $applications->count();

        return back()->with(
            $decided > 0 ? 'status' : 'error',
            $this->summarise($decided, $refused, $data['decision'])
        );
    }

    protected function summarise(int $decided, int $refused, string $decision): string
    {
        $verb = $decision === 'approve' ? 'approved' : 'rejected';

        if ($decided === 0) {
            return 'Nothing was '.$verb.'. Those applications are no longer yours to decide.';
        }

        $message = $decided.' '.str('application')->plural($decided).' '.$verb.'.';

        if ($refused > 0) {
            $message .= ' '.$refused.' skipped, already decided or not yours to act on.';
        }

        return $message;
    }
}
