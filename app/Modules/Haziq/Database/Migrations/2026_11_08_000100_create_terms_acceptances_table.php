<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merges gra_terms_acceptances and ga_terms_acceptances -- identical
 * columns, used the same way, see TermsAcceptance's own docblock and
 * docs/table-audit.md finding A. The two original create migrations are
 * left untouched (everyone has already run them); this one creates the
 * replacement, copies every row across by scheme, then drops the
 * originals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scheme', 10);
            $table->timestamp('accepted_at');
            $table->timestamps();

            // Replaces each old table's own unique(user_id) -- one
            // acceptance per student per scheme, not per student overall.
            $table->unique(['user_id', 'scheme']);
        });

        DB::table('gra_terms_acceptances')->orderBy('id')->each(function ($row) {
            DB::table('terms_acceptances')->insert([
                'user_id' => $row->user_id,
                'scheme' => 'gra',
                'accepted_at' => $row->accepted_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        DB::table('ga_terms_acceptances')->orderBy('id')->each(function ($row) {
            DB::table('terms_acceptances')->insert([
                'user_id' => $row->user_id,
                'scheme' => 'ga',
                'accepted_at' => $row->accepted_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::dropIfExists('gra_terms_acceptances');
        Schema::dropIfExists('ga_terms_acceptances');
    }

    public function down(): void
    {
        Schema::create('gra_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });

        Schema::create('ga_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });

        DB::table('terms_acceptances')->where('scheme', 'gra')->orderBy('id')->each(function ($row) {
            DB::table('gra_terms_acceptances')->insert([
                'user_id' => $row->user_id,
                'accepted_at' => $row->accepted_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        DB::table('terms_acceptances')->where('scheme', 'ga')->orderBy('id')->each(function ($row) {
            DB::table('ga_terms_acceptances')->insert([
                'user_id' => $row->user_id,
                'accepted_at' => $row->accepted_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::dropIfExists('terms_acceptances');
    }
};
