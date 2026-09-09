<?php

namespace App\Modules\Core\Http\Controllers\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

/**
 * Drop this into a module controller and approve/reject is done.
 *
 * Every module gets identical routing, auditing, authorisation and email
 * behaviour, because there is exactly one implementation of it.
 */
trait ApprovesApplications
{
    /** The module_type this controller serves. */
    abstract protected function moduleKey(): string;

    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * The rows sitting on one stage of this module's chain.
     * Pass ?stage=<key>; falls back to the first stage this role owns.
     *
     * @return array{stage: \App\Modules\Core\Support\Stage, applications: \Illuminate\Support\Collection}
     */
    protected function queueFor(Request $request, WorkflowEngine $engine, array $with = []): array
    {
        $queues = app(ModuleRegistry::class)->queuesForRole($request->user()->role);

        $mine = array_values(array_filter(
            $queues,
            fn ($q) => $q['module']->key() === $this->moduleKey()
        ));

        abort_if($mine === [], 403, 'You have no queue in this module.');

        $stageKey = $request->query('stage', $mine[0]['stage']->key);

        $match = collect($mine)->first(fn ($q) => $q['stage']->key === $stageKey);
        abort_unless($match, 403, 'That stage is not yours to act on.');

        return [
            'stage' => $match['stage'],
            'applications' => $engine->queue($this->moduleKey(), $match['stage']->key)->with($with)->get(),
        ];
    }
}
