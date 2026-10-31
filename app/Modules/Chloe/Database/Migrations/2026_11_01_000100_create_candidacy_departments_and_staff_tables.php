<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may act on a Study Candidacy appeal, by department and by name.
 *
 * candidacy_staff_profiles: a person who signs an appeal -- usually linked to a login (user_id), but a profile may
 * exist without one (demo data). The signature is a file of ours (never a shared table).
 * candidacy_staff_assignments: which profile is a lecturer / the chair / the dean of which department. Exactly one
 * chair per department and at most one dean per department (none = the default dean, config('candidacy.default_dean_profile')).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidacy_departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('candidacy_staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('full_name', 150);
            $table->string('designation', 255);
            $table->string('signature_path', 255)->nullable();
            $table->char('signature_sha256', 64)->nullable();
            $table->timestamp('signature_updated_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->foreignId('department_id')->nullable()->constrained('candidacy_departments')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('candidacy_staff_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('candidacy_departments')->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained('candidacy_staff_profiles')->cascadeOnDelete();
            $table->string('role', 10); // lecturer | chair | dean
            $table->timestamps();

            $table->unique(['department_id', 'staff_profile_id', 'role'], 'cand_assign_unique');
            // one chair and at most one dean per department: a generated key that is only set for those two roles
            $table->index(['department_id', 'role']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE candidacy_staff_assignments ADD COLUMN single_holder_key VARCHAR(40) GENERATED ALWAYS AS (IF(role IN ('chair','dean'), CONCAT(department_id, ':', role), NULL)) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX cand_assign_single_holder ON candidacy_staff_assignments (single_holder_key)');
            DB::statement("ALTER TABLE candidacy_staff_assignments ADD CONSTRAINT cand_assign_role_check CHECK (role IN ('lecturer','chair','dean'))");
        }

        if (DB::getDriverName() === 'sqlite') {
            // Same rules for the isolated test database: a partial unique index and a trigger carrying the constraint's name.
            DB::statement("CREATE UNIQUE INDEX cand_assign_single_holder ON candidacy_staff_assignments (department_id, role) WHERE role IN ('chair','dean')");
            DB::statement("CREATE TRIGGER cand_assign_role_check BEFORE INSERT ON candidacy_staff_assignments BEGIN SELECT RAISE(ABORT, 'cand_assign_role_check') WHERE NEW.role NOT IN ('lecturer','chair','dean'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacy_staff_assignments');
        Schema::dropIfExists('candidacy_staff_profiles');
        Schema::dropIfExists('candidacy_departments');
    }
};
