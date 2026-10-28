<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_certification_details', function (Blueprint $table) {
            // Assigned once, at generation time -- see
            // Support\CertificateReferenceGenerator. Nullable because it
            // does not exist before the Senior Director's approval
            // generates the PDF; unique because it is what the public
            // verification page looks a certificate up by.
            $table->string('reference_number', 50)->nullable()->unique()->after('purpose');

            // A certificate stays issued; this is whether it is still
            // trusted. Revoking never touches applications.status -- the
            // application itself stays approved, this is only about
            // whether the PDF on file is still valid.
            $table->timestamp('revoked_at')->nullable()->after('reference_number');
            $table->string('revoked_reason', 255)->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('ga_certification_details', function (Blueprint $table) {
            $table->dropColumn(['reference_number', 'revoked_at', 'revoked_reason']);
        });
    }
};
