<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            // Which list entry this panel member was picked from. The row
            // itself stays a snapshot -- this is only so an appointment can be
            // counted against that examiner's availability. Null for the
            // single-examiner nominations migrated in before the list existed.
            $table->foreignId('pool_examiner_id')->nullable()->after('application_id')
                ->constrained('appointment_examiner_pool')->nullOnDelete();

            // Set when the Dean approves: the moment the appointment becomes
            // real. Starts the examiner's cooldown.
            $table->timestamp('appointed_at')->nullable()->after('letter_ref_no');

            // Set when the mail transport actually accepts this examiner's
            // pack, from the MessageSent listener -- not when it is queued, so
            // a job that dies in the worker leaves this null and the pack
            // shows as undelivered.
            $table->timestamp('pack_sent_at')->nullable()->after('appointed_at');
        });

        $this->backfill();
    }

    /**
     * Nominations approved before these columns existed still appointed real
     * examiners. Without this they would read as never appointed, and their
     * examiners as free to take another panel immediately.
     *
     * Matched on email, which is unique on the list. A snapshot whose email
     * is not on the list any more simply stays unlinked -- it was an
     * examiner entered by hand before the list existed.
     */
    protected function backfill(): void
    {
        foreach (DB::table('appointment_examiner_pool')->select('id', 'email')->get() as $poolExaminer) {
            DB::table('appointment_examiners')
                ->whereNull('pool_examiner_id')
                ->where('examiner_email', $poolExaminer->email)
                ->update(['pool_examiner_id' => $poolExaminer->id]);
        }

        // The Dean's approval is the appointment, so its timestamp is the one
        // the cooldown has always been counted from.
        $approvals = DB::table('approval_history')
            ->where('stage_key', 'dean')
            ->where('decision', '!=', 'rejected')
            ->orderBy('created_at')
            ->pluck('created_at', 'application_id');

        foreach ($approvals as $applicationId => $approvedAt) {
            DB::table('appointment_examiners')
                ->where('application_id', $applicationId)
                ->whereNull('appointed_at')
                ->update(['appointed_at' => $approvedAt]);
        }
    }

    public function down(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pool_examiner_id');
            $table->dropColumn(['appointed_at', 'pack_sent_at']);
        });
    }
};
