<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TA hours in lieu of the GRA/GA tuition waiver. Tracked as a plain
     * activity log (type, hours, who recorded it) rather than the terms
     * document's full per-activity approver/claim-formula engine, which was
     * scoped out as a separate build.
     */
    public function up(): void
    {
        Schema::create('ta_hour_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();

            $table->string('activity_type', 100);
            $table->text('description')->nullable();
            $table->date('activity_date');
            $table->decimal('hours', 5, 2);

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_role', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ta_hour_logs');
    }
};
