<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * proposed_allowance is no longer filled in by the student at
     * submission — CGS sets it when they approve their stage (see
     * GraApplicationController::decide()). It sits null between submission
     * and that approval, so the NOT NULL constraint from the original
     * migration has to go. GA's own proposed_allowance is untouched — the
     * student still sets it there, GA has no CGS/Supervisor/Senior Director
     * review chain for this to hook into.
     *
     * Schema::table(...)->change() rather than raw SQL — raw MySQL ALTER
     * syntax doesn't run on the SQLite connection the test suite uses.
     * ->change() needs doctrine/dbal, added to composer.json for this.
     */
    public function up(): void
    {
        Schema::table('gra_application_details', function (Blueprint $table) {
            $table->decimal('proposed_allowance', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('gra_application_details', function (Blueprint $table) {
            $table->decimal('proposed_allowance', 10, 2)->nullable(false)->change();
        });
    }
};
