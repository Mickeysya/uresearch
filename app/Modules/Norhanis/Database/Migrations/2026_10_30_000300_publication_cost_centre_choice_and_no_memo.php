<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publication changes agreed with CGS:
 *
 *  - The cost centre is now chosen from a list (CGS – Pay and Claims, CGS –
 *    Letter of Undertaking, Split Memo, Others). `cost_centre` holds the
 *    choice; `cost_centre_other` holds the student's words for "Others".
 *  - The split budget memo is no longer signed in UResearch: CGS emails the
 *    template and the supervisor completes it outside the system. Its table
 *    goes. Memo PDFs already attached to an application stay with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->string('cost_centre_other', 150)->nullable()->after('cost_centre');
        });

        Schema::dropIfExists('publication_memos');
    }

    public function down(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn('cost_centre_other');
        });
    }
};
