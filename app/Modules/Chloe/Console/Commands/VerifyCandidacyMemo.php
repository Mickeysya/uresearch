<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Support\CandidacyVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-computes every verification code of an approved appeal from the stored decision data and compares it with the
 * code printed on the memo (stored with the decision); also checks the signature copy still matches its recorded hash.
 * Prints OK or MISMATCH per stage. Read-only.
 */
class VerifyCandidacyMemo extends Command
{
    protected $signature = 'candidacy:verify-memo {appeal_no : e.g. SCA-22001001-01}';

    protected $description = 'Check the verification codes and signature copies of an approved appeal memo';

    public function handle(): int
    {
        $appeal = CandidacyAppeal::where('appeal_no', $this->argument('appeal_no'))->first();

        if (! $appeal) {
            $this->error('No appeal with that number.');

            return self::FAILURE;
        }

        $bad = 0;
        $this->line("Appeal {$appeal->appeal_no} ({$appeal->status})");

        if ($appeal->memo_path === null || ! Storage::disk('local')->exists($appeal->memo_path)) {
            $this->line('Memo file: '.($appeal->memo_path === null ? 'none issued' : 'MISSING'));
            $bad += $appeal->status === CandidacyAppeal::STATUS_APPROVED ? 1 : 0;
        } else {
            $this->line('Memo file: present');
        }

        foreach ($appeal->approvals()->whereIn('decision', ['approved', 'verified'])->orderBy('round')->orderBy('id')->get() as $row) {
            $expected = CandidacyVerification::code($appeal->appeal_no, $row->stage, $row->profile_id, $row->decided_at);
            $codeOk = hash_equals($expected, (string) $row->verification_code);
            $sigOk = $row->signature_snapshot_path === null
                || (Storage::disk('local')->exists($row->signature_snapshot_path)
                    && hash_equals((string) $row->signature_snapshot_sha256, hash('sha256', Storage::disk('local')->get($row->signature_snapshot_path))));

            $ok = $codeOk && $sigOk;
            $bad += $ok ? 0 : 1;
            $this->line(sprintf('%-11s round %d  %-20s %s%s', $row->stage, $row->round, $row->verification_code, $ok ? 'OK' : 'MISMATCH', $ok ? '' : ($codeOk ? ' (signature copy changed)' : ' (code does not match the record)')));
        }

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
