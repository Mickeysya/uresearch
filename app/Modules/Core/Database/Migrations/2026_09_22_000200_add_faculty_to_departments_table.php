<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which faculty a department belongs to, so the picker and the admin list can
 * show the university's actual shape — faculty, then its departments under it
 * — instead of one flat alphabetical list of seventeen names.
 *
 * The same short codes `users.faculty` already stores ('FOE', 'FSMC', 'CFS'),
 * not the full title: see Support/Faculty.php, which holds the titles. Codes
 * so the two columns can be compared, and so a retitled faculty is a one-line
 * label change rather than a data migration.
 *
 * Nullable: a department nobody has filed under a faculty yet still belongs on
 * the list, it just sorts into its own group at the bottom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('faculty', 20)->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['faculty']);
            $table->dropColumn('faculty');
        });
    }
};
