<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sync_runs', function (Blueprint $table) {
            $table->id();

            $table->string('source', 50);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_added')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->unsignedInteger('rows_newly_at_risk')->default(0);
            $table->json('rejection_reasons')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sync_runs');
    }
};
