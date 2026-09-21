<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical department list an admin (or CGS) picks from when creating
 * or editing an account, and the thing a department rename edits.
 *
 * Deliberately its own table rather than a column added anywhere else: the
 * hard rule is no new columns on `users`, and `users.department` already
 * carries the value as free text. This table exists to constrain and rename
 * that text, not to replace it, so a rename is a two-step write (see
 * Admin\DepartmentController::update()) rather than a foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            // Retired rather than deleted: existing accounts keep the name
            // on their record, so it must stay valid history even once it is
            // no longer offered for a new account.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
