<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Contracts\SuppliesDraftCount;
use App\Modules\Core\Models\User;

/**
 * Nureen's saved drafts (GA Extension, GA/GRA Certification), as Core's
 * student dashboard sees them -- see Contracts\SuppliesDraftCount.
 *
 * A one-line wrapper around Drafts::of(), which already scopes to
 * Drafts::MODULES, so the dashboard's badge and the My Drafts page it
 * links to can never disagree about what counts as a draft.
 */
class DraftCountProvider implements SuppliesDraftCount
{
    public function draftsFor(User $student): array
    {
        return [
            'count' => Drafts::of($student)->count(),
            'url' => route('nureen.drafts'),
        ];
    }
}
