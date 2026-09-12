<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's standard database-notification table.
     *
     * Added for the student dashboard's "Unread Notifications" and "Recent
     * Notifications" panels: until now every notification in the portal was
     * mail-only (`via()` returned ['mail']), so nothing the app sent was ever
     * readable back inside the app itself -- the /notifications screen was a
     * placeholder for exactly this reason.
     *
     * A notification opts in by adding 'database' to its via(); anything that
     * does not is unaffected, so this is additive for every module.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->morphs('notifiable');
            $table->string('type');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The dashboard's unread badge and recent-feed both filter on
            // "this user, unread, newest first".
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
