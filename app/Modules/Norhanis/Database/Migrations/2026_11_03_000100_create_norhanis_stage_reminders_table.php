<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per reminder sent about a stalled Travel, Claims or
     * Publication application: which stage it was stuck on and when. It is
     * how norhanis:remind-stalled knows not to remind again too soon, and to
     * tell the student only once per stage.
     *
     * Prefixed, because Nureen's module already has an application_reminders
     * table for her own chains.
     */
    public function up(): void
    {
        Schema::create('norhanis_stage_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('stage_key', 50);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['application_id', 'stage_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('norhanis_stage_reminders');
    }
};
