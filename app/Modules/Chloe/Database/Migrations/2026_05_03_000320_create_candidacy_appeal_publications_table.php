<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section C -- List of Publications/Journal. The paper form has ~15 blank
 * lines but that's page layout, not a limit, so this is a normal repeatable
 * child table rather than a fixed set of columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidacy_appeal_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidacy_appeal_detail_id')->constrained('candidacy_appeal_details')->cascadeOnDelete();
            $table->text('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_appeal_publications');
    }
};
