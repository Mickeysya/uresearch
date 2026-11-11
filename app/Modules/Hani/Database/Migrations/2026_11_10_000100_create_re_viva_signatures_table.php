<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Dean of PGR's signature image, stamped on approved re-viva correction letters. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_viva_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_viva_signatures');
    }
};
