<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\User;

/**
 * Optional companion to WorkflowModule, for a module that stamps a person's
 * signature image onto something it produces.
 *
 * Two modules did, each with its own upload page and its own sidebar entry
 * called "My Signature", so a supervisor saw two identical links and had no
 * way to tell which one an approval needed. Core now has ONE page, "My
 * Signatures" (route signatures.index), and lays out one section per module
 * from what this returns. The module keeps its own storage, upload and
 * removal routes and its own rules -- Core never names its models.
 *
 * The upload and remove forms on Core's page post to the module's routes
 * with `return=/signatures`; the module sends the user back there when it
 * sees that value.
 */
interface SuppliesSignature
{
    /**
     * This user's signature for this module, or null when they never sign
     * anything here (the section is then left out).
     *
     * @return array{
     *     key: string,              // anchor id, e.g. 'hardbound'
     *     title: string,            // e.g. 'Hardbound & appointment letters'
     *     usedFor: string,          // one sentence: what it is stamped on, and when
     *     required: bool,           // true when an approval is blocked without it
     *     imageUrl: ?string,        // the current image, served to its owner only
     *     updatedAt: ?\Carbon\CarbonInterface,
     *     storeRoute: string,       // POST, field name `signature`
     *     destroyRoute: ?string,    // DELETE, or null when it can only be replaced
     *     hint: string,             // accepted files, e.g. 'PNG or JPG, under 1 MB'
     * }|null
     */
    public function signatureFor(User $user): ?array;
}
