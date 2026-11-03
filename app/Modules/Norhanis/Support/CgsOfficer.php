<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;

/**
 * The Non-Executive CGS officer who looks after Travel, Claims and
 * Publication (config/norhanis.cgs.officer): the one ApproverNotifier emails
 * at the CGS stage. Every CGS officer can still see and act on them.
 */
class CgsOfficer
{
    /** The officer's account, or null when there is none to route to. */
    public function user(): ?User
    {
        $email = config('norhanis.cgs.officer');

        return $email ? User::where('email', $email)->where('role', Role::NON_EXEC_CGS)->first() : null;
    }
}
