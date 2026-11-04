<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Claims are now journal-publication claims only. The expense-item
        // breakdown and cash advance belonged to travel reimbursement, which
        // is out of scope here -- see ClaimsController::store().
        Schema::table('claims_details', function (Blueprint $table) {
            $table->dropColumn(['purpose_of_claim', 'less_cash_advance', 'claim_balance', 'total_claim_amount']);
        });

        Schema::table('claims_details', function (Blueprint $table) {
            $table->string('bank_name', 100)->after('application_id');

            // total_claims is a direct student input now, not summed from
            // claims_items -- the trusted check moved to the approver
            // comparing it against the proof-of-payment attachment.
            $table->decimal('total_claims', 10, 2)->default(0)->after('bank_account_no');
        });
    }

    public function down(): void
    {
        Schema::table('claims_details', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'total_claims']);
        });

        Schema::table('claims_details', function (Blueprint $table) {
            $table->string('purpose_of_claim', 255)->after('application_id');
            $table->decimal('total_claim_amount', 10, 2)->default(0);
            $table->decimal('less_cash_advance', 10, 2)->default(0);
            $table->decimal('claim_balance', 10, 2)->default(0);
        });
    }
};
