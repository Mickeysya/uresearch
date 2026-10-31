<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** `is_demo` marks rows made by candidacy:demo-seed so candidacy:demo-clear removes exactly those (our own tables only). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['candidacy_departments', 'candidacy_appeals'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_demo')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['candidacy_departments', 'candidacy_appeals'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('is_demo');
            });
        }
    }
};
