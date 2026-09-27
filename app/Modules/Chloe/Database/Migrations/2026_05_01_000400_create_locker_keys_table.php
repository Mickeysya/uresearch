<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locker_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // requested | collected | returned
            $table->string('status', 20)->default('requested')->index();
            $table->timestamp('requested_at');
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            // Grace-period reminder bookkeeping — see
            // Console\Commands\RemindOverdueLockerKeys.
            $table->timestamp('reminder_sent_at')->nullable();
            $table->unsignedSmallInteger('reminder_count')->default(0);

            $table->boolean('is_override')->default(false);
            $table->foreignId('overridden_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('override_reason', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locker_keys');
    }
};
