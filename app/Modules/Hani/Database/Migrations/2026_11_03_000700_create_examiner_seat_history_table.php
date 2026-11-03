<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to a panel seat, in order.
 *
 * examiner_nominations' four seat columns always hold the CURRENT panel --
 * Jason's appointment-letter module reads them -- so a decline that promotes
 * the backup overwrites them. This table is what remembers who was there
 * before, who declined and why, and who changed it.
 *
 * event: nominated | released | standby | declined | promoted | vacant |
 *        replaced | examined | freed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_seat_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomination_id')->constrained('examiner_nominations')->cascadeOnDelete();
            $table->string('seat', 30)->comment('internal_main_id | internal_backup_id | external_main_id | external_backup_id');
            $table->foreignId('examiner_id')->nullable()->constrained('examiners')->nullOnDelete();
            $table->string('event', 20);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['nomination_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_seat_history');
    }
};
