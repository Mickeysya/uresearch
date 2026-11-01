<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\CandidacyDismissalImport;
use App\Modules\Chloe\Models\CandidacyDismissalImportRow;
use App\Modules\Chloe\Notifications\CandidacyDismissed;
use App\Modules\Chloe\Support\CandidacyDismissalList as L;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The Registry's result comes back as a .xlsx or .csv with a "Student ID" column (the file CGS exported works unchanged). Step 1 PREVIEW classifies every row
 * and saves nothing; step 2 CONFIRM re-checks the same rule (CandidacyDismissalList::evaluate) and, in one transaction, marks the students who are still
 * candidates as dismissed. Re-uploading the same file dismisses nobody twice. Open appeals are not touched.
 */
class CandidacyDismissalUpload
{
    public const MAX_ROWS = 2000;

    public const MAX_KB = 5120;

    public const WILL = 'will';

    public const NOT = 'not_candidate';

    public const ALREADY = 'already';

    public const UNKNOWN = 'unknown';

    public const DUPLICATE = 'duplicate';

    public const LABELS = [self::WILL => 'Will be dismissed', self::NOT => 'Not a current candidate', self::ALREADY => 'Already dismissed', self::UNKNOWN => 'Unknown student ID', self::DUPLICATE => 'Duplicate row in file'];

    /** @return list<array{row:int,id:string}> the "Student ID" cell of every data row (blank rows skipped) */
    public function parse(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload an .xlsx or .csv file.']);
        }

        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages(['file' => 'The file is larger than 5 MB.']);
        }

        try {
            $reader = IOFactory::createReader($ext === 'csv' ? 'Csv' : 'Xlsx');
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($file->getRealPath())->getActiveSheet();
            $table = $sheet->toArray(null, true, false, false);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The file could not be read. Is it a real .xlsx or .csv file?']);
        }

        $column = null;

        foreach ($table[0] ?? [] as $i => $heading) {
            if (strcasecmp(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $heading)), 'Student ID') === 0) {
                $column = $i;
                break;
            }
        }

        if ($column === null) {
            throw ValidationException::withMessages(['file' => 'The file has no "Student ID" column in its first row.']);
        }

        $rows = [];

        foreach (array_slice($table, 1, null, true) as $n => $cells) {
            $value = $cells[$column] ?? null;
            $id = is_float($value) && floor($value) === $value ? (string) (int) $value : trim((string) $value);

            if ($id === '' && ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }

            $rows[] = ['row' => $n + 1, 'id' => $id];
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_ROWS.' rows ('.count($rows).').']);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has no rows below the heading.']);
        }

        return $rows;
    }

    /**
     * @param  list<array{row:int,id:string}>  $rows
     * @return list<array{row:int,id:string,name:?string,status:string,reason:?string,subject:?array}>
     */
    public function classify(array $rows): array
    {
        $today = L::today();
        $subjects = collect(L::subjects())->keyBy(fn ($s) => $s['identity']->studentId);
        $seen = [];
        $out = [];

        foreach ($rows as $r) {
            $id = $r['id'];
            $base = ['row' => $r['row'], 'id' => $id, 'name' => null, 'subject' => null];

            if ($id !== '' && isset($seen[$id])) {
                $out[] = $base + ['status' => self::DUPLICATE, 'reason' => 'Same Student ID as row '.$seen[$id]];

                continue;
            }

            $seen[$id] = $r['row'];
            $identity = $id === '' ? null : StudentDirectory::findByStudentId($id);

            if (! $identity) {
                $out[] = $base + ['status' => self::UNKNOWN, 'reason' => $id === '' ? 'Blank Student ID' : 'No student with this ID'];

                continue;
            }

            $base['name'] = $identity->name;
            $subject = $subjects[$identity->studentId] ?? null;

            if (! $subject) {
                $out[] = $base + ['status' => self::NOT, 'reason' => 'No candidacy record'];

                continue;
            }

            $verdict = L::evaluate($subject, $today);
            $status = match (true) {
                $verdict['candidate'] => self::WILL,
                $verdict['code'] === 'dismissed' => self::ALREADY,
                default => self::NOT,
            };

            $out[] = ['subject' => $subject, 'status' => $status, 'reason' => $verdict['reason']] + $base;
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $classified @return array<string,int> */
    public function counts(array $classified): array
    {
        $c = array_fill_keys(array_keys(self::LABELS), 0);

        foreach ($classified as $r) {
            $c[$r['status']]++;
        }

        return $c + ['total' => count($classified)];
    }

    /**
     * Confirm: re-check every row now and dismiss those still candidates, all in one transaction. A row that stopped being a candidate since the
     * preview is skipped and reported.
     *
     * @param  list<array{row:int,id:string}>  $rows
     * @return CandidacyDismissalImport
     */
    public function apply(array $rows, string $fileName, User $by): CandidacyDismissalImport
    {
        $at = now();
        $notify = [];

        $import = DB::transaction(function () use ($rows, $fileName, $by, $at, &$notify) {
            $classified = $this->classify($rows);
            $will = array_values(array_filter($classified, fn ($r) => $r['status'] === self::WILL));
            $counts = $this->counts($classified);

            $import = CandidacyDismissalImport::create([
                'uploaded_by' => $by->id, 'file_name' => mb_substr($fileName, 0, 250), 'uploaded_at' => $at, 'rows_total' => count($classified),
                'dismissed' => count($will), 'skipped' => count($classified) - count($will),
                'summary' => collect(self::LABELS)->map(fn ($label, $k) => $label.': '.$counts[$k])->implode('; '),
                'is_demo' => collect($will)->contains(fn ($r) => $r['subject']['demo'] !== null),
            ]);

            foreach ($will as $r) {
                $s = $r['subject'];

                if ($s['candidacy']) {
                    $s['candidacy']->forceFill(['status' => \App\Modules\Chloe\Models\StudyCandidacy::STATUS_DISMISSED, 'dismissed_at' => $at, 'dismissed_by_id' => $by->id])->save();
                } else {
                    $s['demo']->forceFill(['status' => 'dismissed', 'dismissed_at' => $at, 'dismissed_by_id' => $by->id])->save();
                }

                CandidacyDismissalImportRow::create(['import_id' => $import->id, 'candidacy_id' => $s['candidacy']?->id, 'demo_profile_id' => $s['demo']?->id, 'student_code' => $s['identity']->studentId, 'dismissed_at' => $at]);
                $notify[] = $s['identity'];
            }

            return $import;
        });

        foreach ($notify as $identity) {
            $identity->notify(new CandidacyDismissed($identity->name));
        }

        return $import;
    }
}
