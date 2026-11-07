<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A saved, unfinished Travel, Publication or Claims form. The
        // application row is Core's own draft (status 'draft', no stage);
        // what the student typed is kept here as it was posted, because the
        // module's detail tables require fields a half-filled form lacks.
        // Uploaded files go to application_documents as usual.
        //
        // A row here is also what tells a saved draft apart from a
        // Publication that is waiting for sign-offs, which is a draft too.
        Schema::create('norhanis_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('payload');
            // The form step the student was on, to reopen it there.
            $table->unsignedTinyInteger('step')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('norhanis_drafts');
    }
};
