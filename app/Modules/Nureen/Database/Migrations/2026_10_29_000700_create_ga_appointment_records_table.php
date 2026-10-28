<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga_appointment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // Provisional: Haziq's GRA/GA module (app/Modules/Haziq) owns
            // this data in principle but has no models or migrations yet --
            // his own README flags the overlap with this module as a
            // decision for the team. Until that lands, this table is what
            // Support\AppointmentVerifier checks a certification request
            // against. See SHARED_CHANGES.md for the real contract this
            // should become.
            $table->string('appointment_type', 10)->comment('GA or GRA');
            $table->date('period_start');
            $table->date('period_end');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_appointment_records');
    }
};
