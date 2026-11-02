<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Nureen\Support\Drafts;
use Illuminate\Http\Request;

/**
 * "My Drafts": the signed-in student's saved GA Extension and Certification
 * drafts, with Continue and Delete. Lives in this module because Core's
 * Track My Applications page lists submitted applications -- see
 * SHARED_CHANGES.md for the Core side.
 */
class DraftsController extends Controller
{
    public function index(Request $request, ModuleRegistry $registry)
    {
        return view('nureen::drafts.index', [
            'drafts' => Drafts::of($request->user())->map(fn ($draft) => [
                'application' => $draft,
                'module' => $registry->labelFor($draft->module_type),
                'summary' => $registry->get($draft->module_type)->summary($draft),
                'saved' => Drafts::lastSaved($draft),
                'files' => $draft->documents->count(),
                'continue' => route(str_replace('_', '-', $draft->module_type).'.draft.edit', $draft),
                'delete' => route(str_replace('_', '-', $draft->module_type).'.draft.destroy', $draft),
            ]),
        ]);
    }
}
