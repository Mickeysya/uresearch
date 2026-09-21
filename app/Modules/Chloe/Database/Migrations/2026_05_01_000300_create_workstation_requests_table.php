<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // confirmed | rejected | released — this module has no approvers,
            // so it never touches applications.status or WorkflowEngine. See
            // WorkstationAllocator.
            $table->string('status', 20)->default('confirmed')->index();
            $table->timestamp('requested_at');
            $table->timestamp('released_at')->nullable();

            // The CGS override exception path (force-assign / force-release).
            // Never set outside WorkstationAllocator's override methods.
            $table->boolean('is_override')->default(false);
            $table->foreignId('overridden_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('override_reason', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_requests');
    }
};
