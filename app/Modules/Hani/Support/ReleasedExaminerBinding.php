<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Contracts\SuppliesReleasedExaminers;

/**
 * ReleasedExaminerProvider under the Core contract's name. Only ever loaded
 * once Hani\ModuleProvider has checked the contract exists (it does not yet:
 * see SHARED_CHANGES.md, section 1a), so its absence breaks nothing.
 */
class ReleasedExaminerBinding extends ReleasedExaminerProvider implements SuppliesReleasedExaminers
{
}
