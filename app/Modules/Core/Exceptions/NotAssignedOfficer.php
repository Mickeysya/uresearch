<?php

namespace App\Modules\Core\Exceptions;

use App\Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A Non-Executive CGS officer tried to decide a module assigned to someone
 * else (config/cgs_assignment.php). Rendered as a 403 carrying the message.
 *
 * Deliberately NOT an UnauthorizedException or a LogicException: the module
 * controllers that call WorkflowEngine::decide() catch those two and turn
 * them into a flash message, and this must stay a 403 wherever it is thrown.
 */
class NotAssignedOfficer extends AccessDeniedHttpException
{
    public function __construct(public readonly ?User $officer)
    {
        parent::__construct(self::messageFor($officer));
    }

    public static function messageFor(?User $officer): string
    {
        return 'This module is assigned to '.($officer?->name ?? 'another CGS officer').'. You can view it but not decide on it.';
    }
}
