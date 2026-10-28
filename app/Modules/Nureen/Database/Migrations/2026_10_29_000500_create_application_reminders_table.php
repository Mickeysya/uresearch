<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();

            // Informational -- how many times a reminder has gone out for
            // this application. Neither GA Extension's nor Certification's
            // stage role ever differs from "whoever genuinely needs to
            // act" the way Supervision's named-supervisor stage does, so
            // there is no escalation decision riding on this count; it is
            // tracked for the analytics page and for any future module
            // that reuses Support\StalledApplicationReminder and does want
            // to escalate.
            $table->unsignedInteger('reminder_count')->default(0);
            $table->string('reminded_stage_key', 50)->nullable();
            $table->timestamp('last_reminded_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_reminders');
    }
};
