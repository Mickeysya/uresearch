<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a correction letter was emailed, or refused, to its examiner:
 * the first send from "Send to panel" and each "Resend letter". Who sent it,
 * when, and the date the link it carried works until. The latest emailed
 * row is the "last sent" shown per examiner.
 *
 * link_expires_at is written when the email is built (in the queue worker),
 * so it is the link the examiner actually got. Letters emailed before this
 * table existed are backfilled as first sends from
 * re_viva_staff_documents.emailed_at, with the link date unknown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_viva_letter_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('re_viva_staff_document_id')->constrained('re_viva_staff_documents')->cascadeOnDelete();
            $table->foreignId('sent_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20)->comment('first or resend');
            $table->boolean('emailed');
            $table->string('skipped_reason')->nullable()->comment('why it was not emailed (flagged or missing email, mail failure)');
            $table->timestamp('link_expires_at')->nullable()->comment('set when the email is built: the link it carried works until then');
            $table->timestamps();

            $table->index(['re_viva_staff_document_id', 'created_at']);
        });

        DB::table('re_viva_staff_documents')
            ->where('kind', 'correction_letter')
            ->whereNotNull('emailed_at')
            ->orderBy('id')
            ->each(function ($letter) {
                DB::table('re_viva_letter_sends')->insert([
                    're_viva_staff_document_id' => $letter->id,
                    'sent_by_id' => $letter->created_by_id,
                    'kind' => 'first',
                    'emailed' => true,
                    'created_at' => $letter->emailed_at,
                    'updated_at' => $letter->emailed_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_viva_letter_sends');
    }
};
