<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();

            $table->string('stage_key', 60)->index();
            // The label as it read when the decision was made, so relabelling a
            // stage later does not silently rewrite the audit trail.
            $table->string('stage_label', 150);

            // endorsed | reviewed | approved | rejected -- the positive verb is
            // chosen by the Stage, so a module can say "reviewed" without
            // needing an ALTER on a shared ENUM.
            $table->string('decision', 20)->index();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_history');
    }
};
