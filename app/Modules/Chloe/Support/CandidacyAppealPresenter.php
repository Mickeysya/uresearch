<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use Illuminate\Support\Collection;

/** Everything a page needs to SHOW about an appeal, in one place so every page and role says the same thing. */
final class CandidacyAppealPresenter
{
    public const STAGES = [
        'supervisor' => 'Supervisor',
        'chair' => 'Programme Chair',
        'cgs' => 'CGS Verification',
        'dean' => 'Dean of PGR',
    ];

    /** The colour key of a status (see the legend on every page that shows one). */
    public static function tone(string $status): string
    {
        return match ($status) {
            CandidacyAppeal::STATUS_APPROVED => 'green',
            CandidacyAppeal::STATUS_WITH_SUPERVISOR, CandidacyAppeal::STATUS_WITH_CHAIR, CandidacyAppeal::STATUS_WITH_CGS, CandidacyAppeal::STATUS_WITH_DEAN => 'gold',
            CandidacyAppeal::STATUS_RETURNED => 'orange',
            CandidacyAppeal::STATUS_REJECTED => 'red',
            default => 'slate', // draft, closed_expired
        };
    }

    public static function statusLabel(CandidacyAppeal $appeal): string
    {
        return match ($appeal->status) {
            CandidacyAppeal::STATUS_DRAFT => 'Draft',
            CandidacyAppeal::STATUS_WITH_SUPERVISOR => 'With Supervisor',
            CandidacyAppeal::STATUS_WITH_CHAIR => 'With Programme Chair',
            CandidacyAppeal::STATUS_WITH_CGS => 'With CGS',
            CandidacyAppeal::STATUS_WITH_DEAN => 'With Dean',
            CandidacyAppeal::STATUS_RETURNED => 'Returned to student',
            CandidacyAppeal::STATUS_APPROVED => 'Approved',
            CandidacyAppeal::STATUS_REJECTED => 'Rejected (final)',
            CandidacyAppeal::STATUS_CLOSED_EXPIRED => 'Closed (expired)',
            default => ucfirst($appeal->status),
        };
    }

    /** The legend every page that shows status colours carries. */
    public static function legend(): array
    {
        return ['green' => 'Approved', 'gold' => 'Waiting for a step', 'orange' => 'Returned: needs your action', 'red' => 'Rejected (final)', 'slate' => 'Draft or closed'];
    }

    /** Who the appeal is waiting for, as shown in "Current step". */
    public static function currentStep(CandidacyAppeal $appeal): string
    {
        if ($appeal->current_stage) {
            $who = match ($appeal->current_stage) {
                'supervisor' => $appeal->supervisorProfile?->full_name,
                'chair' => $appeal->chairProfile?->full_name,
                'dean' => $appeal->deanProfile?->full_name,
                default => 'Centre for Graduate Studies',
            };

            return self::STAGES[$appeal->current_stage].($who ? " ({$who})" : '');
        }

        return match ($appeal->status) {
            CandidacyAppeal::STATUS_RETURNED => 'Student (returned by '.(self::STAGES[$appeal->returned_by_stage] ?? 'a step').')',
            CandidacyAppeal::STATUS_DRAFT => 'Student (draft)',
            default => '—',
        };
    }

    /** @return list<array{key:string,label:string,person:?string,date:?\Carbon\CarbonInterface,state:string,waiting:?int,code:?string}> */
    public static function stepBar(CandidacyAppeal $appeal): array
    {
        $latest = $appeal->approvals->sortBy('id')->groupBy('stage')->map->last();
        $bar = [];

        foreach (self::STAGES as $key => $label) {
            /** @var CandidacyAppealApproval|null $row */
            $row = $latest->get($key);
            $state = match (true) {
                $appeal->status === CandidacyAppeal::STATUS_APPROVED => 'done',
                $appeal->current_stage === $key => 'current',
                $appeal->status === CandidacyAppeal::STATUS_RETURNED && $appeal->returned_by_stage === $key => 'returned',
                $appeal->status === CandidacyAppeal::STATUS_REJECTED && $key === 'dean' => 'rejected',
                $row && in_array($row->decision, ['approved', 'verified'], true) => 'done',
                default => 'pending',
            };

            $person = match ($key) {
                'supervisor' => $appeal->supervisorProfile?->full_name,
                'chair' => $appeal->chairProfile?->full_name,
                'dean' => $appeal->deanProfile?->full_name,
                default => $row?->full_name ?? 'Centre for Graduate Studies',
            };

            $bar[] = [
                'key' => $key, 'label' => $label, 'person' => $person, 'date' => $row?->decided_at, 'state' => $state,
                'waiting' => $state === 'current' ? $appeal->waitingDays() : null, 'code' => $row?->verification_code,
            ];
        }

        return $bar;
    }

    public static function stateLabel(string $state): string
    {
        return ['done' => 'Done', 'current' => 'Waiting here', 'returned' => 'Returned the appeal', 'rejected' => 'Rejected', 'pending' => 'Not reached yet'][$state] ?? $state;
    }

    public static function reasonLabels(?string $stage, ?string $action, ?array $codes): string
    {
        if (! $codes) {
            return '';
        }

        $list = (array) config("candidacy.reasons.{$stage}.".($action === 'rejected' ? 'reject' : 'return'), []);

        return implode('; ', array_map(fn ($c) => $list[$c] ?? $c, $codes));
    }

    public static function eventLabel(CandidacyAppealEvent $event): string
    {
        $stage = self::STAGES[$event->stage] ?? null;

        return match ($event->action) {
            'submitted' => 'Submitted by the student',
            'resubmitted' => 'Resubmitted by the student'.($stage ? " to {$stage}" : ''),
            'approved' => "Approved by {$stage}",
            'verified' => 'Verified by CGS',
            'returned' => "Returned to the student by {$stage}",
            'rejected' => 'Rejected by the Dean (final)',
            'closed_expired' => 'Closed: not resubmitted before the candidacy expired',
            default => ucfirst(str_replace('_', ' ', $event->action)),
        };
    }

    /** Decisions with their reasons and remarks, newest first (the "Remarks and reasons" card). */
    public static function remarks(CandidacyAppeal $appeal): Collection
    {
        return $appeal->events->filter(fn ($e) => in_array($e->action, ['returned', 'rejected'], true) || ($e->remark && $e->candidacy_appeal_id))
            ->sortByDesc('id')->values();
    }

    /** Timeline of the appeal's own events (signature uploads belong to a person, not an appeal). */
    public static function timeline(CandidacyAppeal $appeal): Collection
    {
        return $appeal->events->whereNotNull('candidacy_appeal_id')->sortByDesc('id')->values();
    }

    public static function date($date, string $format = 'j M Y'): string
    {
        return $date ? $date->copy()->setTimezone('Asia/Kuala_Lumpur')->format($format) : '—';
    }
}
