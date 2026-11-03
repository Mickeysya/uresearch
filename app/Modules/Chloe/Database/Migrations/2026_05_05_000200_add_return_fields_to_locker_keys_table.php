<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing locker_keys table for the Collected -> Return
 * Pending -> Returned leg (seat released/reassigned while the key was
 * already collected). Existing rows/columns are untouched -- returned_at
 * already exists from the original migration, only return_due_at and
 * return_reminder_sent_at are new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_keys', function (Blueprint $table) {
            $table->timestamp('return_due_at')->nullable()->after('returned_at');
            $table->timestamp('return_reminder_sent_at')->nullable()->after('return_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('locker_keys', function (Blueprint $table) {
            $table->dropColumn(['return_due_at', 'return_reminder_sent_at']);
        });
    }
};
