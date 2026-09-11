<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga_certification_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Self-declared by the student, then checked by CGS staff at the
            // cgs_verify stage -- their endorsement there IS the verification;
            // there is no separate stored "verified" flag to trust instead.
            $table->string('appointment_type', 10)->comment('GA or GRA');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('purpose', 255)->comment('e.g. scholarship application, visa renewal');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_certification_details');
    }
};
