<?php

use App\Modules\Core\Support\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // VARCHAR rather than ENUM on purpose -- see Support\Role for why.
            $table->string('role', 50)->index()->comment('see App\Modules\Core\Support\Role');

            // Student fields
            $table->string('matric_no', 30)->nullable()->unique();
            $table->string('programme', 100)->nullable()->comment('e.g. MSc Full-Time, PhD Part-Time');
            $table->string('contact_no', 30)->nullable();

            // Organisational placement. Absent from the legacy schema, which is
            // why cross-department examiner conflict detection had nothing to
            // key on and why approvals could not be routed to a specific chair.
            $table->string('department', 150)->nullable()->index();
            $table->string('faculty', 100)->nullable()->index()->comment('e.g. FOE, FSMC');

            // Student -> supervisor link, also absent from the legacy schema.
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
