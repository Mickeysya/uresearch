<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Optional companion to WorkflowModule.
 *
 * The sidebar builds itself from the approval chain, which covers the two
 * common cases: a student starting an application, and an approver working a
 * queue. Anything else needs declaring — Hani's examiner nominations, for
 * instance, are filed by a supervisor who owns no stage in that chain and so
 * would otherwise have no way to reach the form.
 *
 * Implement this alongside WorkflowModule only when you need it.
 */
interface ProvidesLinks
{
    /**
     * Extra sidebar links for this user, or [] for none.
     *
     * @return array<int, array{label: string, route: string, params?: array}>
     */
    public function links(User $user): array;
}
