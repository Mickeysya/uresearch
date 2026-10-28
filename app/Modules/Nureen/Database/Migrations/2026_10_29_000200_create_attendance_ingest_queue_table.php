<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_ingest_queue', function (Blueprint $table) {
            $table->id();

            // One record, as posted, untouched until PushEndpointSource
            // parses it through the same AttendanceIngestor path every
            // other source uses.
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_ingest_queue');
    }
};
