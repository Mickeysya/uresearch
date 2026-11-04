<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\WorkstationStatistics;
use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * PART C: the twelve suggested questions' answers -- never a model call,
 * so picking one never spends quota. STATIC ones (S1, S4, S5, S6, C4, C5,
 * C6) are one "### id" section of docs/knowledge/workstation/
 * suggested_answers.md, substituted through GuidanceLibrary's own
 * {{token}} values. DYNAMIC ones (S2, S3, C1, C2, C3) are built here from
 * the exact same lookups/functions the chat's own lookups and the
 * Insights page use (WorkstationStatistics, LockerKey, StudyCandidacy),
 * so a suggested answer can never disagree with either of those.
 */
final class SuggestedAnswers
{
    /** @return array<string, string> id => raw (unsubstituted) section text */
    protected static function staticSections(): array
    {
        static $sections = null;

        if ($sections !== null) {
            return $sections;
        }

        $path = base_path('docs/knowledge/workstation/suggested_answers.md');
        $sections = [];

        if (! File::exists($path)) {
            return $sections;
        }

        $parts = preg_split('/^### (\w+)\s*$/m', File::get($path), -1, PREG_SPLIT_DELIM_CAPTURE);

        // $parts[0] is the preamble before the first heading; after that,
        // captured id / body pairs alternate.
        for ($i = 1; $i < count($parts); $i += 2) {
            $sections[$parts[$i]] = trim($parts[$i + 1] ?? '');
        }

        return $sections;
    }

    public static function staticText(string $id): ?string
    {
        $section = self::staticSections()[$id] ?? null;

        return $section === null ? null : GuidanceLibrary::applyPlaceholders($section);
    }

    /** @return array{text: string, links: array<int, array{label: string, url: string}>} */
    public static function dynamic(string $id, User $user, string $role): array
    {
        return match ($id) {
            's2_loan_end' => self::loanEnd($user),
            's3_locker_key' => self::lockerKeyStatus($user),
            'c1_release_outlook' => self::releaseOutlook(),
            'c2_almost_full' => self::almostFull(),
            'c3_termination_detailed' => self::terminationDetailed(),
            default => ['text' => 'Sorry, I could not find information on that topic. Please contact the Centre for Graduate Studies (CGS).', 'links' => []],
        };
    }

    protected static function loanEnd(User $user): array
    {
        $request = WorkstationRequest::with('activeTerminationNotice')
            ->where('student_id', $user->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        if (! $request) {
            return ['text' => 'You do not currently hold a workstation.', 'links' => [
                ['label' => 'Select a workstation', 'url' => route('workstation.select')],
            ]];
        }

        $notice = $request->activeTerminationNotice;

        if ($notice) {
            return ['text' => 'Your loan has been terminated. Please vacate by '.$notice->vacate_by_date->format('j M Y').'.', 'links' => [
                ['label' => 'My Workstation', 'url' => route('workstation.select')],
            ]];
        }

        $expiry = StudyCandidacy::where('student_id', $user->id)->value('candidacy_expiry_date');
        $dateText = $expiry ? Carbon::parse($expiry)->format('j M Y') : 'an unknown date';

        $text = "Your loan is valid until {$dateText} (your candidacy expiry date, including any approved extension). When it ends, or if your loan is terminated for another reason in the Term of Use, you receive a termination notice by email and must vacate within ".
            WorkstationAllocator::TERMINATION_NOTICE_WORKING_DAYS.
            ' working days. The workstation is then released automatically. If you collected a locker key, return it to CGS by the same date. You can select a workstation again if you are still eligible.';

        return ['text' => $text, 'links' => [
            ['label' => 'Postgraduate Workstation Term of Use', 'url' => route('workstation.terms')],
        ]];
    }

    protected static function lockerKeyStatus(User $user): array
    {
        $request = WorkstationRequest::where('student_id', $user->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        $key = $request
            ? LockerKey::where('workstation_request_id', $request->id)->latest('requested_at')->first()
            : LockerKey::where('student_id', $user->id)->where('status', LockerKey::STATUS_RETURN_PENDING)->latest('requested_at')->first();

        $links = [['label' => 'My Workstation', 'url' => route('workstation.select')]];

        if (! $key) {
            $text = 'You have not requested a locker key. Request it on your Workstation page, then collect it at CGS within '.LockerKey::COLLECTION_PERIOD_DAYS.
                ' days. A reminder is sent on day '.LockerKey::GRACE_DAYS.
                ', and an uncollected request is cancelled automatically after '.LockerKey::COLLECTION_PERIOD_DAYS.' days.';

            return ['text' => $text, 'links' => $links];
        }

        $text = match ($key->status) {
            LockerKey::STATUS_REQUESTED => 'Your key was requested on '.$key->requested_at->format('j M Y').'. Collect it at CGS by '.$key->collectByDate()->format('j M Y').'.',
            LockerKey::STATUS_COLLECTED => 'Your key was collected on '.$key->collected_at->format('j M Y').'. Return it to CGS when you leave your workstation.',
            LockerKey::STATUS_RETURN_PENDING => 'Please return your locker key to CGS by '.$key->return_due_at?->format('j M Y').'.'.
                ($key->isOverdueForReturn() ? ' This is overdue.' : '').
                ' CGS: Centre for Graduate Studies, Monday to Friday, 8:00 AM - 5:00 PM (GMT +8.00).',
            default => 'Your locker key status: '.ucfirst(str_replace('_', ' ', $key->status)).'.',
        };

        return ['text' => $text, 'links' => $links];
    }

    protected static function releaseOutlook(): array
    {
        $o = WorkstationStatistics::seatReleaseOutlook(3);

        $lines = [];
        $lines[] = 'Termination notice: '.$o['termination']['count'].' seat(s), released automatically after their vacate-by date'.
            ($o['termination']['earliest_vacate_by'] ? ' (earliest '.$o['termination']['earliest_vacate_by'].').' : '.');
        $lines[] = 'Awaiting Key Return: '.$o['awaiting_key_return']['count'].' seat(s), available again once CGS marks the key as returned.';
        $lines[] = 'Candidacy expiring within 3 months: '.$o['candidacy_expiring']['count'].
            " seat holder(s). Their loan ends at expiry unless an appeal extends it (up to 12 months in total), so treat this as the maximum.";

        return ['text' => implode("\n", $lines), 'links' => [
            ['label' => 'Termination notices', 'url' => route('workstation.cgs.operations', ['seat_status' => 'termination_notice'])],
            ['label' => 'Awaiting Key Return', 'url' => route('workstation.cgs.operations', ['seat_status' => 'awaiting_key_return'])],
        ]];
    }

    protected static function almostFull(): array
    {
        $threshold = (float) config('assistant.almost_full_percent', 90);
        $rooms = WorkstationStatistics::almostFullRooms($threshold);

        if ($rooms === []) {
            $fullest = WorkstationStatistics::fullestRoom();
            $text = $fullest
                ? "No room is at {$threshold}% yet. The fullest is {$fullest['room_code']} at {$fullest['occupied_percent']}%."
                : "No room is at {$threshold}% yet.";

            return ['text' => $text, 'links' => []];
        }

        $lines = collect($rooms)->map(fn ($r) => "{$r['room_code']}: {$r['occupied_percent']}% occupied, {$r['available']} available")->implode("\n");
        $links = collect($rooms)->take(5)->map(fn ($r) => ['label' => $r['room_code'], 'url' => route('workstation.cgs.room', $r['id'])])->all();

        return ['text' => $lines, 'links' => $links];
    }

    protected static function terminationDetailed(): array
    {
        $d = WorkstationStatistics::terminationNoticesDetailed();

        $byReason = collect($d['by_reason'])->map(fn ($count, $reason) => "{$reason}: {$count}")->implode(', ');
        $text = "{$d['count']} seats. These students have received a termination notice and keep their seat until the vacate-by date (".
            WorkstationAllocator::TERMINATION_NOTICE_WORKING_DAYS.' working days after the notice).';

        if ($d['earliest_vacate_by']) {
            $text .= " Earliest vacate-by date: {$d['earliest_vacate_by']}.";
        }
        if ($byReason !== '') {
            $text .= " By reason: {$byReason}.";
        }

        return ['text' => $text, 'links' => [
            ['label' => 'Filtered list', 'url' => route('workstation.cgs.operations', ['seat_status' => 'termination_notice'])],
        ]];
    }
}
