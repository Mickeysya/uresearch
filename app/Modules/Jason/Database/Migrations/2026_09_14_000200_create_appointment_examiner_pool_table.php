<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The list the Chair picks a panel from, the same way they pick the
        // candidate. Kept separate from Hani's `examiners` pool on purpose:
        // hers serves her nomination and conflict-detection chain, and a
        // column change there must not be able to break a letter here.
        //
        // A nomination snapshots the chosen rows into appointment_examiners,
        // so editing an entry here never rewrites a letter already issued.
        Schema::create('appointment_examiner_pool', function (Blueprint $table) {
            $table->id();

            $table->string('examiner_type', 20)->index();
            $table->string('name', 150);
            $table->string('institution', 150);
            $table->text('address')->nullable();
            $table->string('email', 150)->unique();
            $table->string('expertise', 255);

            // Retired examiners stay for the record but drop out of the list.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_examiner_pool');
    }
};
