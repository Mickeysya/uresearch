<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            // The one supervisor this application's 'supervisor' stage is
            // addressed to -- captured at submission from the student's
            // users.supervisor_id, the same reasoning as Certification's
            // ga_certification_details.supervisor_id: if the student's
            // supervisor changes later, the application stays with whoever
            // it was addressed to. Before this, the stage belonged to
            // everyone holding the Supervisor role, so any supervisor could
            // see and endorse any student's GA Extension.
            $table->foreignId('supervisor_id')->nullable()->after('application_id')
                ->constrained('users')->nullOnDelete();
        });

        // Existing rows: the student's CURRENT supervisor -- the best record
        // there is of who the application was addressed to. A correlated
        // subquery rather than UPDATE ... JOIN so it runs on the SQLite test
        // database as well as MySQL.
        DB::table('ga_extension_details')
            ->whereNull('supervisor_id')
            ->update(['supervisor_id' => DB::raw(
                '(SELECT users.supervisor_id FROM applications'
                .' JOIN users ON users.id = applications.student_id'
                .' WHERE applications.id = ga_extension_details.application_id)'
            )]);
    }

    public function down(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
        });
    }
};
