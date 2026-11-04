<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate from gra_terms_acceptances on purpose — confirmed explicitly
     * 2026-11-03: "ga is different from gra" — the two schemes' own terms
     * and regulations are different documents, so accepting one never
     * counts as accepting the other. Same shape otherwise: one row per
     * student, not per application, so accepting once covers every GA
     * application that student ever submits afterwards.
     */
    public function up(): void
    {
        Schema::create('ga_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga_terms_acceptances');
    }
};
