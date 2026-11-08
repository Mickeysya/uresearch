<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_completeness_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();

            // Informational, same shape as Nureen's application_reminders --
            // no escalation decision rides on this count, it just throttles
            // the weekly job (Support\DocumentCompleteness::remindedRecently())
            // and feeds the "last reminded" column on the report.
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_reminded_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_completeness_reminders');
    }
};
