<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ga_extension_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->date('current_end_date');
            $table->date('requested_new_end_date');
            $table->text('reason_for_extension');

            // The supporting file lives in application_documents like every
            // other upload; it is no longer a path column on this table.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_extension_details');
    }
};
