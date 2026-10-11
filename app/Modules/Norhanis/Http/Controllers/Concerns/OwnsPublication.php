<?php

namespace App\Modules\Norhanis\Http\Controllers\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Http\Request;

/**
 * The student's side of a publication's sign-offs is spread over two
 * controllers, and both must apply the same rule before touching anything.
 */
trait OwnsPublication
{
    /** The publication behind an application, only for the student who filed it. */
    protected function ownDetail(Request $request, Application $application): PublicationDetail
    {
        abort_unless($application->module_type === 'publication', 404);
        abort_unless($application->student_id === $request->user()->id, 403);

        return PublicationDetail::where('application_id', $application->id)
            ->with('authors', 'activeEvaluations', 'memo')
            ->firstOrFail();
    }
}
