<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The official CGS re-examination process: student submission, document
 * check, Dean approval, panel deadline, meetings, and the settings the Senior
 * Executive can change in the portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->timestamp('documents_accepted_at')->nullable()->after('logged_outside_window');
            $table->timestamp('dean_approved_at')->nullable()->after('documents_accepted_at');
            $table->date('panel_deadline')->nullable()->after('sent_to_panel_by_id');
            $table->string('meeting_kind', 20)->nullable()->after('panel_deadline')->comment('consolidation | re_viva');
            $table->dateTime('meeting_at')->nullable()->after('meeting_kind');
            $table->string('meeting_venue')->nullable()->after('meeting_at');
        });

        Schema::create('re_viva_document_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('re_viva_detail_id')->constrained('re_viva_details')->cascadeOnDelete();
            $table->json('documents');
            $table->text('reason');
            $table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('re_viva_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_viva_settings');
        Schema::dropIfExists('re_viva_document_returns');

        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->dropColumn(['documents_accepted_at', 'dean_approved_at', 'panel_deadline', 'meeting_kind', 'meeting_at', 'meeting_venue']);
        });
    }
};
