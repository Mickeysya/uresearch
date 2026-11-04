<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What `re-viva:monitor` has already sent: written BEFORE sending, and the
 * unique key makes a second run skip it (the rpd:remind pattern, as
 * viva_reminder_logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_viva_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('re_viva_detail_id')->constrained('re_viva_details')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('reference', 64)->default('')->comment('e.g. the days-before threshold');
            $table->timestamp('sent_at');

            $table->unique(['re_viva_detail_id', 'kind', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_viva_reminder_logs');
    }
};
