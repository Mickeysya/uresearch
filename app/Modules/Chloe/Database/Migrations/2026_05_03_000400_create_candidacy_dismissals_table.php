<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidacy_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_candidacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // expired_no_appeal | dean_rejected | extension_exhausted
            $table->string('reason', 30);

            $table->timestamp('generated_at');

            // pending_review | confirmed — CGS reviews before anything is
            // submitted to Registry, and confirms only after Registry has
            // actually processed it. Registry's own process stays outside
            // this system; see Console\Commands\GenerateCandidacyDismissals.
            $table->string('status', 20)->default('pending_review')->index();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_dismissals');
    }
};
