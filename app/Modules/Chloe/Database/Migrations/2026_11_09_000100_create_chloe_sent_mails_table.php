<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent archive of every e-mail the system sends (Chloe module, independent of Mailpit). One row per message,
 * written by Listeners\ArchiveSentMail from Laravel's MessageSent event; the full raw message is kept as an .eml file
 * under storage/app/private/mail-archive/YYYY/MM/. Append-only: nothing in the codebase updates or deletes a row.
 * Additive and new: no existing table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chloe_sent_mails')) {
            return;
        }

        Schema::create('chloe_sent_mails', function (Blueprint $table) {
            $table->id();
            // Asia/Kuala_Lumpur wall-clock time, stored as written (no timezone conversion on read).
            $table->dateTime('sent_at')->index();
            $table->text('to_addresses');
            $table->text('cc_addresses')->nullable();
            $table->string('subject', 500);
            // Notification or Mailable class; null for a raw message (Mail::raw).
            $table->string('mail_class', 255)->nullable();
            // workstation | candidacy | the owning module's folder (lower case) | other
            $table->string('module', 30)->index();
            // Best effort from subject/body: appeal number, seat or room code, reminder months.
            $table->string('reference', 255)->nullable();
            $table->string('message_id', 255)->nullable();
            // Relative to the archive root, e.g. 2026/11/20261107-224500-ab12cd34.eml
            $table->string('eml_path', 500);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chloe_sent_mails');
    }
};
