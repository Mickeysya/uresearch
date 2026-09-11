<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_appeal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // The specific flagged record being appealed, if the student
            // filed from their at-risk banner. Nullable: an appeal can also
            // be filed as a general dispute of the uploaded data.
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->text('reason');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_appeal_details');
    }
};
