<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Implemented by whichever module(s) support saved drafts; consumed by the
 * student dashboard's "My Drafts" link.
 *
 * Counting `applications.status = 'draft'` unscoped would overcount the
 * moment a second module adds draft support of its own (or undercount if a
 * module's "My Drafts" page only ever shows some of its own module types,
 * as Nureen's does -- Support\Drafts::MODULES). So Core asks the owning
 * module how many of ITS drafts exist, the same inversion as
 * SuppliesAttendance, rather than querying applications.status by itself
 * and guessing which rows belong to which "My Drafts" page.
 *
 *     $this->app->bind(SuppliesDraftCount::class, YourDraftCountProvider::class);
 */
interface SuppliesDraftCount
{
    /**
     * This student's drafts, for the dashboard's "My Drafts" link -- `url`
     * travels with `count` rather than Core hardcoding a module's route
     * name, same as the other Supplies* cards' `url` key.
     *
     * @return array{count: int, url: string}
     */
    public function draftsFor(User $student): array;
}
