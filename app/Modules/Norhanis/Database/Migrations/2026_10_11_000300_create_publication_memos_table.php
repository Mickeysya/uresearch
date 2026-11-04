<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Memo Request Split Budget, written by the system instead of
        // typed into a downloaded template. Only a publication whose fee is
        // above what the CGS fund covers has one.
        Schema::create('publication_memos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_detail_id')->unique()->constrained()->cascadeOnDelete();

            // The split, in ringgit. Stored rather than recomputed, so the
            // memo keeps saying what was signed even if the cap later changes.
            $table->decimal('fee_myr', 10, 2);
            $table->decimal('cgs_amount', 10, 2);
            $table->decimal('grant_amount', 10, 2);

            $table->string('grant_name', 150);
            $table->string('grant_cost_centre', 100);
            $table->string('centre_name', 150);

            // FROM: the student's supervisor. THROUGH: the head of the
            // research centre that holds the grant. Each signs from a link.
            foreach (['supervisor', 'head'] as $signer) {
                $table->string("{$signer}_name", 150);
                $table->string("{$signer}_email");
                $table->timestamp("{$signer}_invited_at")->nullable();
                $table->mediumText("{$signer}_signature_png")->nullable(); // base64, no data: prefix
                $table->timestamp("{$signer}_signed_at")->nullable();
                $table->string("{$signer}_signed_ip", 45)->nullable();
            }
            $table->string('supervisor_department', 150)->nullable();

            // Either signer can send it back instead of signing.
            $table->timestamp('declined_at')->nullable();
            $table->string('declined_by', 20)->nullable(); // supervisor | head
            $table->string('decline_reason', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_memos');
    }
};
