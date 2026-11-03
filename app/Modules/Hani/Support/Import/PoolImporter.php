<?php

namespace App\Modules\Hani\Support\Import;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Support\ExaminerContact;
use App\Modules\Hani\Models\ExaminerReviewItem;
use App\Modules\Hani\Support\Departments;
use App\Modules\Hani\Support\NameNormaliser;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns CGS's examiner pool spreadsheet into the pool, in two steps.
 *
 *   read()      sheets -> PoolRow[], whichever of the two layouts the file is
 *   classify()  each row against the pool as it stands NOW: New, Update,
 *               Unchanged, Possible duplicate or Rejected (with the reason),
 *               plus a "Missing email" flag
 *   apply()     writes it, after classifying again, so a preview that sat
 *               open while someone else edited the pool cannot apply stale
 *               decisions
 *
 * It replaces the manual Excel list CGS keeps, so it holds rows to exactly the
 * rules the Add Examiner form does -- institution required for an external
 * examiner, an internal one stripped of the external record, a reason for
 * anyone Unavailable -- plus one the form never needed: the department must
 * resolve to a UResearch department (Support\Departments), or the
 * own-department rule could never be satisfied.
 *
 * It never deletes an examiner, never invents a nomination, and never lets a
 * sheet move someone's last examination date backwards (that would shorten a
 * 90-day gap the system already started).
 */
class PoolImporter
{
    public const NEW = 'new';
    public const UPDATE = 'update';
    public const UNCHANGED = 'unchanged';
    public const DUPLICATE = 'duplicate';
    public const REJECTED = 'rejected';

    public const LABELS = [
        self::NEW => 'New',
        self::UPDATE => 'Update',
        self::UNCHANGED => 'Unchanged',
        self::DUPLICATE => 'Possible duplicate',
        self::REJECTED => 'Rejected',
    ];

    public function __construct(
        protected Departments $departments,
        protected NameNormaliser $names,
        protected CgsLayoutReader $cgs,
        protected TemplateLayoutReader $template,
    ) {}

    /**
     * Every sheet of a file, as Excel::toArray() returns them, into rows.
     *
     * @param  array<int, array<int, array<int, mixed>>>  $sheets
     * @return array{layout: ?string, rows: array<int, PoolRow>, messages: array<int, string>}
     */
    public function read(array $sheets, array $names = []): array
    {
        $rows = [];
        $publications = [];
        $messages = [];
        $layouts = [];

        foreach ($sheets as $index => $sheet) {
            $label = $names[$index] ?? 'Sheet '.($index + 1);
            [$headerIndex, $headers] = $this->headerRow($sheet);

            if ($headerIndex === null) {
                continue;
            }

            $body = array_slice($sheet, $headerIndex + 1);
            $first = $headerIndex + 2; // spreadsheet row numbers are 1-based

            if (CgsLayoutReader::isInternal($headers)) {
                $layouts['cgs'] = true;
                $rows = array_merge($rows, $this->cgs->internal($names[$index] ?? 'Internal Examiner', $body, $headers, $first));
            } elseif (CgsLayoutReader::isExternal($headers)) {
                $layouts['cgs'] = true;
                $rows = array_merge($rows, $this->cgs->external($names[$index] ?? 'External Examiner', $body, $headers, $first));
            } elseif (TemplateLayoutReader::isExaminers($headers)) {
                $layouts['template'] = true;
                $rows = array_merge($rows, $this->template->examiners($names[$index] ?? 'Examiners', $body, $headers, $first));
            } elseif (TemplateLayoutReader::isPublications($headers)) {
                $layouts['template'] = true;
                $publications = array_merge_recursive($publications, $this->template->publications($names[$index] ?? 'Publications', $body, $headers, $first));
            } else {
                $messages[] = "{$label} was ignored: its header row is not an examiner list.";
            }
        }

        if (count($layouts) > 1) {
            return ['layout' => null, 'rows' => [], 'messages' => ['This file mixes CGS\'s layout with the UResearch template. Upload one or the other.']];
        }

        $rows = $this->prepare($rows, $publications, $messages);

        return ['layout' => array_key_first($layouts), 'rows' => $rows, 'messages' => $messages];
    }

    /**
     * Rows already read (from a sheet, or from HaniDemoSeeder's JSON) made
     * ready to classify: publications attached by email, every row held to
     * the import rules. The step a spreadsheet and the seeder share.
     *
     * @param  array<int, PoolRow>  $rows
     * @param  array<string, array<int, array>>  $publications  by examiner email
     * @return array<int, PoolRow>
     */
    public function prepare(array $rows, array $publications = [], array &$messages = []): array
    {
        $rows = $this->attachPublications($rows, $publications, $messages);

        foreach ($rows as $row) {
            $this->validate($row);
        }

        return $rows;
    }

    /**
     * Each row against the pool as it stands now.
     *
     * @param  array<int, PoolRow>  $rows
     * @return array<int, array{status: string, examiner: ?Examiner, messages: array<int, string>, missing_email: bool}>
     */
    public function classify(array $rows): array
    {
        $pool = Examiner::with('expertiseKeywords', 'publications')->get();
        $byEmail = $pool->filter(fn (Examiner $e) => filled($e->email))->keyBy(fn (Examiner $e) => mb_strtolower($e->email));

        $seenEmails = [];
        $newSoFar = [];
        $out = [];

        foreach ($rows as $i => $row) {
            $email = $row->get('email');
            $result = ['status' => self::NEW, 'examiner' => null, 'messages' => [], 'missing_email' => blank($email)];

            if ($row->errors) {
                $out[$i] = ['status' => self::REJECTED, 'messages' => $row->errors] + $result;

                continue;
            }

            if ($email && isset($seenEmails[$email])) {
                $out[$i] = ['status' => self::REJECTED, 'messages' => ["Same email as {$seenEmails[$email]}."]] + $result;

                continue;
            }

            if ($email) {
                $seenEmails[$email] = $row->source;
            }

            $match = $email ? $byEmail->get($email) : null;
            $match ??= $this->matchByName($row, $pool);

            if ($match && $match->type !== $row->type()) {
                $out[$i] = ['status' => self::REJECTED, 'messages' => [
                    "{$match->name} is already in the pool as an {$match->type} examiner; this row says {$row->type()}.",
                ]] + $result;

                continue;
            }

            if ($match) {
                $changes = $this->changes($row, $match);
                $out[$i] = [
                    'status' => $changes ? self::UPDATE : self::UNCHANGED,
                    'examiner' => $match,
                    'messages' => $changes,
                ] + $result;

                continue;
            }

            // New -- unless the name looks like someone already in the pool,
            // or like an earlier new row in this same file. Flagged, never
            // blocked: two people can share a name.
            $alike = $this->lookAlike($row, $pool, $newSoFar);

            if ($alike) {
                $out[$i] = ['status' => self::DUPLICATE, 'messages' => [$alike]] + $result;
            } else {
                $out[$i] = $result;
            }

            $newSoFar[] = $row;
        }

        return $out;
    }

    /**
     * Writes the rows. Rejected rows are skipped; possible duplicates are
     * added (flagged in the pool until someone marks them "Not the same
     * person" or deals with them) -- never blocking is the rule.
     *
     * @param  array<int, PoolRow>  $rows
     * @return array<string, int> counts by status, plus review items raised
     */
    public function apply(array $rows, ?int $batchId = null, ?User $by = null): array
    {
        return DB::transaction(function () use ($rows, $batchId, $by) {
            $classified = $this->classify($rows);
            $counts = array_fill_keys(array_keys(self::LABELS), 0) + ['review_items' => 0, 'missing_email' => 0];

            foreach ($rows as $i => $row) {
                $result = $classified[$i];
                $counts[$result['status']]++;
                $counts['missing_email'] += (int) ($result['missing_email'] && $result['status'] !== self::REJECTED);

                if ($result['status'] === self::REJECTED) {
                    continue;
                }

                $before = $result['examiner']?->email;
                $examiner = $result['examiner']
                    ? $this->update($result['examiner'], $row)
                    : Examiner::create($row->attributes + ['is_active' => true]);

                // The import only ever fills a missing email (updatable()),
                // and that goes in the contact history like any other change.
                if ($examiner->email !== null && $examiner->email !== $before) {
                    $examiner->update(['email_confirmed_at' => now()]);
                    app(ExaminerContact::class)->log($examiner, $before, $examiner->email, $by, 'import', $batchId ? "Import batch #{$batchId}" : null);
                }

                $this->syncKeywords($examiner, $row);
                $this->addPublications($examiner, $row);
                $counts['review_items'] += $this->raiseReviewItems($examiner, $row, $batchId);
            }

            return $counts;
        });
    }

    /** The first row of the first five with two or more filled cells. */
    protected function headerRow(array $sheet): array
    {
        foreach (array_slice($sheet, 0, 5, true) as $index => $cells) {
            $filled = array_filter($cells, fn ($c) => Cells::text($c) !== null);

            if (count($filled) >= 2) {
                return [$index, array_map([Cells::class, 'header'], $cells)];
            }
        }

        return [null, []];
    }

    /** The rules every row is held to, whichever layout it came from. */
    protected function validate(PoolRow $row): void
    {
        if (blank($row->get('name'))) {
            $row->errors[] = 'Name is missing.';
        }

        if (! in_array($row->type(), [Examiner::TYPE_INTERNAL, Examiner::TYPE_EXTERNAL], true)) {
            // The template reader has already said what the bad value was.
            if ($row->errors === [] || blank($row->type())) {
                $row->errors[] = 'Type is missing: it must be internal or external.';
            }

            return;
        }

        $email = $row->get('email');

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row->errors[] = "\"{$email}\" is not a valid email address.";
        } elseif ($email === null) {
            $row->warnings[] = 'Missing email. The examiner is added, but invitations and letters will skip them until one is recorded.';
        }

        $resolved = $this->departments->resolve($row->departmentAsWritten);

        if ($resolved) {
            $row->attributes['department'] = $resolved['name'];
            $row->attributes['faculty'] = $resolved['faculty'];
        } elseif ($row->type() === Examiner::TYPE_INTERNAL) {
            $row->errors[] = $row->departmentAsWritten
                ? "Department \"{$row->departmentAsWritten}\" is not a UResearch department and no alias maps it. Add it to config('hani.departments.aliases') or correct the sheet."
                : 'Department is missing.';
        } elseif ($row->departmentAsWritten) {
            // An external examiner's department is often their own
            // institution's ("Department of Structures and Materials"), which
            // no UTP list will know. Kept as written.
            $row->attributes['department'] = mb_substr($row->departmentAsWritten, 0, 150);
            $row->warnings[] = "Department \"{$row->departmentAsWritten}\" is not a UTP department; kept as written.";
        } else {
            $row->errors[] = 'Department is missing.';
        }

        if ($row->type() === Examiner::TYPE_INTERNAL) {
            $row->attributes = Arr::except($row->attributes, Examiner::EXTERNAL_FIELDS);
        } elseif (blank($row->get('institution'))) {
            $row->errors[] = 'An external examiner needs an institution.';
        }

        if (($row->attributes['is_active'] ?? true) === false && blank($row->get('unavailable_reason'))) {
            $row->errors[] = 'Marked unavailable without a reason. Give one (retired, resigned, …) or mark them available.';
        }
    }

    /**
     * No email to go on: the same normalised name, the same type, and the same
     * department (internal) or institution (external). Exact, not fuzzy -- a
     * fuzzy match is a "possible duplicate" for a person to judge, never a
     * silent update of somebody else's record.
     */
    protected function matchByName(PoolRow $row, Collection $pool): ?Examiner
    {
        $name = $this->names->normalise($row->get('name'));

        return $pool->first(function (Examiner $e) use ($row, $name) {
            if ($e->type !== $row->type() || $this->names->normalise($e->name) !== $name) {
                return false;
            }

            // Only fill a missing email, never overwrite a different one.
            if (filled($e->email) && filled($row->get('email')) && mb_strtolower($e->email) !== $row->get('email')) {
                return false;
            }

            return $row->type() === Examiner::TYPE_INTERNAL
                ? $this->departments->same($e->department, $row->get('department'))
                : $this->sameInstitution($e->institution, $row->get('institution'));
        });
    }

    protected function sameInstitution(?string $a, ?string $b): bool
    {
        $key = fn ($s) => Departments::normalise(preg_replace('/\([^)]*\)/', '', (string) $s));

        return $key($a) !== '' && $key($a) === $key($b);
    }

    /** A sentence naming who this row looks like, or null. */
    protected function lookAlike(PoolRow $row, Collection $pool, array $newSoFar): ?string
    {
        $best = null;

        $candidates = $pool->where('type', $row->type())
            ->map(fn (Examiner $e) => ['name' => $e->name, 'where' => 'in the pool'])
            ->concat(collect($newSoFar)
                ->filter(fn (PoolRow $r) => $r->type() === $row->type())
                ->map(fn (PoolRow $r) => ['name' => $r->get('name'), 'where' => "at {$r->source}"]));

        foreach ($candidates as $candidate) {
            $score = $this->names->similarity($row->get('name'), $candidate['name']);

            if ($score >= (int) config('hani.duplicates.threshold', 85) && $score > ($best['score'] ?? 0)) {
                $best = $candidate + ['score' => $score];
            }
        }

        return $best
            ? "\"{$row->get('name')}\" looks like \"{$best['name']}\" {$best['where']} ({$best['score']}% similar). Added as a separate examiner; mark \"Not the same person\" in the pool once checked."
            : null;
    }

    /**
     * What applying this row would change, in words. Empty means Unchanged,
     * which is what makes a second import of the same file a no-op.
     *
     * @return array<int, string>
     */
    protected function changes(PoolRow $row, Examiner $examiner): array
    {
        $changes = [];

        foreach ($this->updatable($row, $examiner) as $field => $value) {
            $current = $examiner->{$field};
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;

            if ((string) $current !== (string) $value && ! (is_bool($value) && (bool) $current === $value)) {
                $changes[] = str_replace('_', ' ', ucfirst($field)).': '
                    .($current === null || $current === '' ? '(blank)' : (is_bool($current) ? ($current ? 'yes' : 'no') : $current))
                    .' → '.(is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? '(blank)'));
            }
        }

        $newKeywords = array_diff($row->keywords, $examiner->expertiseKeywords->pluck('keyword')->all());
        $goneKeywords = $row->keywords ? array_diff($examiner->expertiseKeywords->pluck('keyword')->all(), $row->keywords) : [];

        if ($newKeywords || $goneKeywords) {
            $changes[] = 'Expertise keywords: '.count($newKeywords).' added, '.count($goneKeywords).' removed';
        }

        $titles = $examiner->publications->map(fn ($p) => mb_strtolower($p->title))->all();
        $newPublications = array_filter($row->publications, fn ($p) => ! in_array(mb_strtolower($p['title']), $titles, true));

        if ($newPublications) {
            $changes[] = count($newPublications).' new '.str('publication')->plural(count($newPublications));
        }

        return $changes;
    }

    /**
     * The fields this row may write to an existing examiner: what the sheet
     * gave (a blank cell never erases a value), an email only where none is
     * on file, and the last examination date only if it is later.
     *
     * @return array<string, mixed>
     */
    protected function updatable(PoolRow $row, Examiner $examiner): array
    {
        $values = $row->attributes;
        unset($values['type']);

        if (filled($examiner->email)) {
            unset($values['email']);
        }

        if (isset($values['last_examination_date'], $examiner->last_examination_date)
            && $values['last_examination_date'] <= $examiner->last_examination_date->format('Y-m-d')) {
            unset($values['last_examination_date']);
        }

        // Reactivating clears the reason; a blank reason on an active row is not a change.
        if (($values['is_active'] ?? null) === true) {
            $values['unavailable_reason'] = null;

            if ($examiner->unavailable_reason === null) {
                unset($values['unavailable_reason']);
            }
        }

        return array_filter($values, fn ($v, $k) => $v !== null || $k === 'unavailable_reason', ARRAY_FILTER_USE_BOTH);
    }

    protected function update(Examiner $examiner, PoolRow $row): Examiner
    {
        $examiner->fill($this->updatable($row, $examiner))->save();

        return $examiner;
    }

    /** The sheet's keyword list is the examiner's list, when the sheet has one. */
    protected function syncKeywords(Examiner $examiner, PoolRow $row): void
    {
        if ($row->keywords === []) {
            return;
        }

        $current = $examiner->expertiseKeywords()->pluck('keyword')->all();

        foreach (array_diff($row->keywords, $current) as $keyword) {
            $examiner->expertiseKeywords()->create(['keyword' => $keyword]);
        }

        // Deleted model by model, so ExaminerExpertise::$touches fires.
        $examiner->expertiseKeywords()->whereNotIn('keyword', $row->keywords)->get()->each->delete();
    }

    /** Adds publications the examiner does not already have. Never removes one. */
    protected function addPublications(Examiner $examiner, PoolRow $row): void
    {
        $titles = $examiner->publications()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();

        foreach ($row->publications as $publication) {
            if (! in_array(mb_strtolower($publication['title']), $titles, true)) {
                $examiner->publications()->create(Arr::only($publication, ['title', 'year', 'coauthors']));
                $titles[] = mb_strtolower($publication['title']);
            }
        }
    }

    /**
     * CGS's sheet lists a student the examiner is holding. Recorded for the
     * Senior Executive to check against UResearch's nominations; never turned
     * into one. The same item is not raised twice while it is still open.
     */
    protected function raiseReviewItems(Examiner $examiner, PoolRow $row, ?int $batchId): int
    {
        if ($row->students === []) {
            return 0;
        }

        $items = [[ExaminerReviewItem::KIND_CURRENT_ASSIGNMENT, $row->students[0]]];

        if (count($row->students) > 1) {
            $items[] = [ExaminerReviewItem::KIND_MULTIPLE_STUDENTS, 'Also listed: '.implode(', ', array_slice($row->students, 1))];
        }

        $raised = 0;

        foreach ($items as [$kind, $detail]) {
            $detail = mb_substr($detail, 0, 500);

            $exists = ExaminerReviewItem::open()
                ->where('examiner_id', $examiner->id)
                ->where('kind', $kind)
                ->where('detail', $detail)
                ->exists();

            if (! $exists) {
                ExaminerReviewItem::create([
                    'examiner_id' => $examiner->id,
                    'kind' => $kind,
                    'detail' => $detail,
                    'import_batch_id' => $batchId,
                ]);
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Publications sheet rows onto the examiner rows with the same email.
     * One that matches no row in the file is matched against the pool at
     * classify time instead; one that matches neither is reported.
     */
    protected function attachPublications(array $rows, array $publications, array &$messages): array
    {
        $byEmail = [];

        foreach ($rows as $row) {
            if ($row->get('email')) {
                $byEmail[$row->get('email')] = $row;
            }
        }

        foreach ($publications as $email => $list) {
            if (isset($byEmail[$email])) {
                $byEmail[$email]->publications = array_merge($byEmail[$email]->publications, $list);

                continue;
            }

            $examiner = Examiner::whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $examiner) {
                foreach ($list as $publication) {
                    $messages[] = "{$publication['source']}: no examiner with the email {$email}; publication skipped.";
                }

                continue;
            }

            // A publications-only upload for someone already in the pool:
            // carried as a minimal row that changes nothing else.
            $row = new PoolRow($list[0]['source']);
            $row->attributes = array_filter([
                'type' => $examiner->type,
                'name' => $examiner->name,
                'email' => $email,
                'institution' => $examiner->institution,
            ]);
            $row->departmentAsWritten = $examiner->department;
            $row->publications = $list;
            $rows[] = $row;
        }

        return $rows;
    }
}
