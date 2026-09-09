<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // type_of_request and other_request_specify were on Norhanis' form
            // but in nobody's schema.sql, so every travel submission failed.
            $table->string('type_of_request', 50);
            $table->string('other_request_specify', 255)->nullable();

            $table->date('travel_start_date');
            $table->date('travel_end_date');
            $table->unsignedSmallInteger('duration_days');
            $table->string('reason_for_travel', 255);
            $table->string('destination_address', 255);

            // Drives the approval chain — see TravelWorkflow::stages().
            $table->boolean('is_international')->default(false)->index();

            $table->string('contact_person_name', 150)->nullable();
            $table->string('contact_person_no', 30)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_details');
    }
};
