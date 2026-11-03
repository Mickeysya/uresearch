<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 8 of the viva flow: the AE finalises the date and sends the
        // calendar. All nullable: a viva is unscheduled until the AE sets it.
        Schema::table('viva_details', function (Blueprint $table) {
            // Local time (APP_TIMEZONE). The invite converts it to UTC.
            $table->dateTime('viva_at')->nullable()->after('expected_softbound_date');
            $table->string('venue', 255)->nullable()->after('viva_at');

            // Neither the chairman nor a co-supervisor is a system user or
            // stored anywhere else, so the AE types them in here.
            $table->string('chair_name', 150)->nullable()->after('venue');
            $table->string('chair_email', 150)->nullable()->after('chair_name');
            $table->string('co_supervisor_name', 150)->nullable()->after('chair_email');
            $table->string('co_supervisor_email', 150)->nullable()->after('co_supervisor_name');

            $table->foreignId('scheduled_by_id')->nullable()->after('co_supervisor_email')
                ->constrained('users')->nullOnDelete();

            // Bumped on every reschedule. The invite's SEQUENCE, so a
            // calendar replaces the old event instead of adding a second.
            $table->unsignedSmallInteger('schedule_sequence')->default(0)->after('scheduled_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('viva_details', function (Blueprint $table) {
            $table->dropForeign(['scheduled_by_id']);
            $table->dropColumn([
                'viva_at', 'venue', 'chair_name', 'chair_email',
                'co_supervisor_name', 'co_supervisor_email', 'scheduled_by_id', 'schedule_sequence',
            ]);
        });
    }
};
