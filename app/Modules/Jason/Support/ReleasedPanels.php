<?php

namespace App\Modules\Jason\Support;

use App\Modules\Core\Contracts\SuppliesReleasedExaminers;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;

/**
 * The examiner panels the nomination chain has released, ready for letters.
 *
 * The list used to arrive as a spreadsheet emailed between two desks in
 * the same office and retyped at this end. Core now carries the contract
 * that hands it over in code, and the nomination chain binds it, so that
 * is the ordinary path. The spreadsheet import remains as the manual
 * fallback -- for a panel released before the hand-off existed, and for a
 * checkout where the chain is not installed.
 *
 * Nothing in this class writes, and nothing in it reaches into another
 * member's folder: the contract is Core's, and the fallback path goes
 * through ExaminerDirectory, which is this module's own read-only gateway.
 */
final class ReleasedPanels
{
    /**
     * Resolved from the container by name rather than type-hinted, so the
     * binding alone decides whether the hand-off is live. It was a plain
     * string while the file was only proposed; it is the real class now.
     */
    public const CONTRACT = SuppliesReleasedExaminers::class;

    /**
     * Whether the hand-off is live, or the spreadsheet is still the way in.
     *
     * The binding decides. Core having the interface says only that the
     * contract exists; it is the nomination chain binding an implementation
     * that means there is something to read. A checkout without that module
     * has the file and nothing behind it.
     */
    public static function available(): bool
    {
        return app()->bound(self::CONTRACT);
    }

    /**
     * Every released panel, newest first, or none when the hand-off is off.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (! self::available()) {
            return [];
        }

        $panels = app(self::CONTRACT)->releasedPanels();

        return array_values(array_map(
            fn (array $panel) => self::shape($panel),
            is_array($panels) ? $panels : [],
        ));
    }

    /**
     * A released panel in the terms this module works in.
     *
     * Only the two mains: the backups are the panel's reserve and are
     * appointed only if a main declines, at which point the chain has
     * already promoted them into the main seat -- so a backup never needs
     * reading here, and a promoted one arrives as a main.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    protected static function shape(array $panel): array
    {
        $seats = [];

        foreach (['internal_main' => 'internal', 'external_main' => 'external'] as $slot => $type) {
            $seat = $panel['examiners'][$slot] ?? null;

            if (! is_array($seat) || ($seat['name'] ?? '') === '') {
                continue;
            }

            $seats[] = [
                'hani_examiner_id' => isset($seat['id']) ? (int) $seat['id'] : null,
                'examiner_type' => $type,
                'examiner_role' => AppointmentExaminer::ROLE_MAIN,
                'examiner_name' => (string) $seat['name'],
                'examiner_email' => (string) ($seat['email'] ?? ''),
                // The chain says in its own words why an address cannot be
                // used. Shown as given rather than reworded: it is the desk
                // that knows what went wrong with it.
                'email_problem' => self::problemWith($seat),
                'examiner_institution' => (string) ($seat['institution'] ?? $seat['department'] ?? ''),
                'examiner_department' => (string) ($seat['department'] ?? ''),
            ];
        }

        return [
            'nomination_application_id' => isset($panel['application_id']) ? (int) $panel['application_id'] : null,
            'released_at' => $panel['released_at'] ?? null,
            'student' => $panel['student'] ?? [],
            'supervisor' => $panel['supervisor'] ?? [],
            'thesis_title' => (string) ($panel['thesis_title'] ?? ''),
            'examiners' => $seats,
        ];
    }

    /** @param  array<string, mixed>  $seat */
    protected static function problemWith(array $seat): ?string
    {
        if (($seat['email_problem'] ?? null) !== null && $seat['email_problem'] !== '') {
            return (string) $seat['email_problem'];
        }

        return ($seat['email'] ?? '') === '' || $seat['email'] === null
            ? 'No email address on record. CGS updates it in the Examiner Pool.'
            : null;
    }

    /**
     * The examiners on a nomination who already have a letter.
     *
     * Keyed by the nomination's own application number, because that is how
     * the chain refers to it and how this module stored it. One nomination
     * can have produced several appointments -- a promoted backup gets a
     * letter of their own rather than being added to one the Dean has
     * already signed -- so every appointment against it is counted.
     *
     * @return array{exists: bool, ids: array<int, int>, names: array<int, string>}
     */
    public static function alreadyLettered(int $nominationApplicationId): array
    {
        $applicationIds = AppointmentDetail::where('nomination_application_id', $nominationApplicationId)
            ->pluck('application_id');

        if ($applicationIds->isEmpty()) {
            return ['exists' => false, 'ids' => [], 'names' => []];
        }

        $rows = AppointmentExaminer::whereIn('application_id', $applicationIds)
            ->get(['hani_examiner_id', 'examiner_name']);

        return [
            // An appointment exists, whether or not its rows can be tied to
            // the chain's own examiner ids. The two are different facts and
            // conflating them is what hid an appointment from both screens.
            'exists' => true,
            'ids' => $rows->pluck('hani_examiner_id')->filter()->map(fn ($id) => (int) $id)
                ->unique()->values()->all(),
            // Rows written before this module stored the chain's ids, and
            // rows matched by name in the first place, have no id to compare.
            // Their names are the only handle on them -- the same handle the
            // importer itself falls back to.
            'names' => $rows->pluck('examiner_name')
                ->filter()
                ->map(fn ($name) => mb_strtolower(trim($name)))
                ->unique()->values()->all(),
        ];
    }

    /**
     * Whether this seat has already been written to.
     *
     * By the chain's examiner id where this module has one. Where it does
     * not -- an appointment imported before that column existed, or one
     * matched by name to begin with -- the name is all there is, and it is
     * the same comparison the importer trusts to identify an examiner in
     * the first place. Getting this wrong by name would withhold a letter
     * rather than send a second, which is the safer way to be wrong.
     *
     * @param  array{exists: bool, ids: array<int, int>, names: array<int, string>}  $lettered
     * @param  array<string, mixed>  $seat
     */
    protected static function seatIsLettered(array $lettered, array $seat): bool
    {
        if ($seat['hani_examiner_id'] && in_array($seat['hani_examiner_id'], $lettered['ids'], true)) {
            return true;
        }

        return in_array(mb_strtolower(trim((string) $seat['examiner_name'])), $lettered['names'], true);
    }

    /**
     * Current main examiners who have no letter yet.
     *
     * This is what a decline looks like from this side. The chain always
     * shows the CURRENT panel, so when a main declines, the backup is
     * already sitting in the main seat the next time it is read -- there is
     * no "declined" event to catch. The difference between who is in the
     * seat now and who this module has written to is the whole signal.
     *
     * A promoted backup gets a letter of their own rather than being added
     * to a pack the Dean has already signed, so the comparison is by
     * examiner, not by nomination.
     *
     * Works with or without the contract. Without it the current panel is
     * read through this module's own gateway, for the nominations it has
     * already imported -- which is exactly the set a decline can happen on.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lettersNeeded(): array
    {
        $needed = [];

        foreach (self::currentPanels() as $panel) {
            $nominationId = $panel['nomination_application_id'];

            if (! $nominationId) {
                continue;
            }

            $lettered = self::alreadyLettered($nominationId);

            // No appointment against this nomination at all, so it is a
            // first import rather than a promotion. The import screen
            // handles those; listing them here would ask CGS to open four
            // one-examiner appointments instead of one.
            //
            // The test is whether an appointment EXISTS, not whether its
            // examiners can be identified. An appointment whose rows carry
            // no examiner id is still an appointment, and treating it as
            // nothing hid it from this screen while the importer was
            // skipping it as already done -- invisible on both.
            if (! $lettered['exists']) {
                continue;
            }

            foreach ($panel['examiners'] as $seat) {
                if (self::seatIsLettered($lettered, $seat)) {
                    continue;
                }

                $needed[] = $seat + [
                    'nomination_application_id' => $nominationId,
                    'student' => $panel['student'],
                    'thesis_title' => $panel['thesis_title'],
                ];
            }
        }

        return $needed;
    }

    /**
     * Every panel worth comparing, from whichever source is available.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function currentPanels(): array
    {
        if (self::available()) {
            return self::all();
        }

        $panels = [];

        $details = AppointmentDetail::whereNotNull('nomination_application_id')
            ->with('application.student')
            ->get()
            ->unique('nomination_application_id');

        foreach ($details as $detail) {
            $seats = array_values(array_filter(
                ExaminerDirectory::panelForNomination($detail->nomination_application_id),
                fn (array $e) => $e['examiner_role'] === AppointmentExaminer::ROLE_MAIN,
            ));

            if ($seats === []) {
                continue;
            }

            $student = $detail->application?->student;

            $panels[] = [
                'nomination_application_id' => (int) $detail->nomination_application_id,
                'released_at' => null,
                'student' => ['name' => $student?->name, 'matric_id' => $student?->matric_no],
                'supervisor' => ['name' => $detail->supervisor_name],
                'thesis_title' => (string) $detail->thesis_title,
                'examiners' => $seats,
            ];
        }

        return $panels;
    }
}
