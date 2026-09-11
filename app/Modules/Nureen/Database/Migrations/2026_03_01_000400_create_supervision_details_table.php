<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervision_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Who the student is asking for. Kept here rather than inferred
            // from users.supervisor_id, because that column is what approval
            // of THIS request sets -- it cannot also be the source of it.
            $table->foreignId('requested_supervisor_id')->constrained('users')->restrictOnDelete();
            $table->text('justification');

            // Last time a stall-escalation reminder was sent for this request,
            // so `supervision:remind-stalled` never re-notifies the same
            // pending decision twice in one run. Lives here, not on the
            // shared `applications` table, which this module may not alter.
            $table->timestamp('reminded_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_details');
    }
};
