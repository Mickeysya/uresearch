<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_authors', function (Blueprint $table) {
            $table->id();

            // Linked to publication_details, NOT applications — a publication
            // can have many authors. See PublicationDetail::authors().
            $table->foreignId('publication_detail_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->boolean('is_corresponding_author')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_authors');
    }
};
