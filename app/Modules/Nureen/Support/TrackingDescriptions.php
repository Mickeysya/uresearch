<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\WorkflowEngine;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * What Track My Applications says about this module's applications, beyond
 * Core's generic card (Core\Contracts\DescribesApplicationProgress,
 * implemented on the workflows).
 *
 *   letters      an approved Supervision or GA/GRA Certification
 *                application with an issued letter: the letter's real
 *                reference, Download letter (PDF) as the primary button,
 *                and "needs you" while the student has not opened it yet
 *                (Core's document download logs every view); a revoked
 *                letter says so and offers no download
 *   GA Extension pending: "All 4 required documents received." when they
 *                all did (otherwise Core's generic sentence)
 *
 * Documents come from the relation Core has already eager-loaded, so this
 * adds one query per letter (the "opened yet?" check) and none for GA
 * Extension.
 */
class TrackingDescriptions
{
    /** @return array<string, mixed> */
    public function letter(Application $application, string $docType, ?string $reference, ?CarbonInterface $revokedAt): array
    {
        if ($application->status !== Application::STATUS_APPROVED || ! $reference) {
            return [];
        }

        $document = $this->documents($application)->where('doc_type', $docType)->sortByDesc('id')->first();
        $approved = $application->relationLoaded('history') ? $application->history->last() : null;
        $approvedText = $approved ? 'Approved by '.$approved->stage_label.' on '.$approved->created_at->format('j M Y').'.' : 'Approved.';

        if ($revokedAt) {
            return [
                'reference' => $reference,
                'sentence' => "{$approvedText} This letter ({$reference}) was revoked on {$revokedAt->format('j M Y')}; contact CGS if you need a replacement.",
                'next' => 'This letter is no longer valid. If you still need one, contact CGS quoting the reference; there is nothing else to do on this application.',
            ];
        }

        if (! $document) {
            return ['reference' => $reference, 'sentence' => "{$approvedText} Your letter ({$reference}) is being prepared."];
        }

        $opened = Activity::where('log_name', 'document')
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->id)
            ->where('causer_id', $application->student_id)
            ->where('properties->action', 'viewed')
            ->exists();

        return [
            'reference' => $reference,
            'sentence' => "{$approvedText} Your letter ({$reference}) is ready.",
            'actions' => [['label' => 'Download letter (PDF)', 'url' => route('documents.show', $document), 'primary' => true]],
            'needs_student' => ! $opened,
            'next' => $opened
                ? 'Your letter has been issued. Anyone you give it to can check it on its verification page. You don\'t need to do anything else.'
                : 'Your letter has been issued. Download it and keep it for your records; you haven\'t opened it yet, so this needs you.',
        ];
    }

    /** @return array<string, mixed> */
    public function gaExtension(Application $application): array
    {
        if ($application->status !== Application::STATUS_PENDING) {
            return [];
        }

        $required = array_values((array) config('nureen.ga_extension.required_documents', []));
        $have = $this->documents($application)->pluck('doc_type')->unique();
        $received = count(array_filter($required, fn ($label) => $have->contains($label)));
        $stage = app(WorkflowEngine::class)->currentStage($application);

        // Only the reassuring case is worth saying. A student cannot add
        // documents to a submitted request, so "2 of 4 received" would ask
        // for something they cannot do -- and older rows predate the
        // requirement. Otherwise Core's generic sentence stands.
        if ($required === [] || $received < count($required)) {
            return [];
        }

        return ['sentence' => 'All '.count($required).' required documents received. Now with '.($stage?->label ?? 'the reviewer').'. No action needed from you.'];
    }

    /** @return \Illuminate\Support\Collection<int, ApplicationDocument> */
    protected function documents(Application $application)
    {
        return $application->relationLoaded('documents') ? $application->documents : $application->documents()->get();
    }
}
