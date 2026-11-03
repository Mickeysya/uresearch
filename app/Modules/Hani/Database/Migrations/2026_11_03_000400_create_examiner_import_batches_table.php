<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pool file between "upload" and "confirm".
 *
 * The uploaded file itself is never stored: it is parsed straight from the
 * request's temporary file, and only the parsed rows are kept here, so the
 * preview can be shown and then applied without asking for the file again.
 * Nothing under public/, nothing on disk -- see ExaminerImportController.
 * Unconfirmed batches are purged after config('hani.import.batch_ttl_hours').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examiner_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('layout', 20)->comment('cgs | template');
            $table->string('filename', 255);
            $table->json('rows');
            $table->json('messages')->nullable()->comment('file-level notes: ignored sheets, orphan publications');
            $table->json('summary')->nullable()->comment('counts written on confirm');
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examiner_import_batches');
    }
};
