<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART 3: tracks a termination notice (Term of Use clause 9/16) against
 * the WorkstationRequest it was issued on -- one row per notice, so a
 * withdrawn one and a later fresh one on the same occupancy both have
 * their own history rather than overwriting each other. Exactly one
 * row with status='active' may exist per request at a time (enforced in
 * WorkstationAllocator::startTermination(), not a DB constraint -- same
 * portability reasoning as the rest of this module's generated-column
 * tricks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workstation_termination_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_request_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 150);
            $table->date('notice_date');
            $table->date('vacate_by_date');

            // active | released | withdrawn
            $table->string('status', 20)->default('active')->index();

            $table->string('withdrawn_reason', 150)->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_termination_notices');
    }
};
