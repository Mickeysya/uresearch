<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round 4, seat catalogue + seat selection. Idempotent: every step checks before it changes anything, and nothing here
 * touches existing seat rows except to add a NULL deleted_at.
 *
 *  - workstations.deleted_at           a seat is retired (soft deleted), never hard deleted: history rows keep pointing at it.
 *  - workstation_seat_counter          one persistent counter for the next seat number. Only ever incremented, so a retired or
 *                                      deleted number is never handed out again. Initialised to the highest number ever used.
 *  - workstation_requests.workstation_id_if_confirmed
 *                                      generated column (NULL unless the request is the confirmed one) with a UNIQUE index: the
 *                                      database refuses a second active assignment of the same seat, whatever the application does.
 *                                      Same trick as student_id_if_confirmed (one seat per student).
 *  - workstation_locations.layout_image_*   the optional room layout image (stored on the private disk, served through a route).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workstations', 'deleted_at')) {
            Schema::table('workstations', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('workstation_seat_counter')) {
            Schema::create('workstation_seat_counter', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        if (! DB::table('workstation_seat_counter')->where('id', 1)->exists()) {
            // withTrashed on purpose: a retired seat's number still counts as used.
            $max = (int) DB::table('workstations')->pluck('seat_code')
                ->filter(fn ($code) => ctype_digit((string) $code))
                ->map(fn ($code) => (int) $code)
                ->max();

            DB::table('workstation_seat_counter')->insert(['id' => 1, 'last_number' => $max, 'created_at' => now(), 'updated_at' => now()]);
        }

        if (! Schema::hasColumn('workstation_requests', 'workstation_id_if_confirmed')) {
            Schema::table('workstation_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('workstation_id_if_confirmed')
                    ->virtualAs("CASE WHEN status = 'confirmed' THEN workstation_id ELSE NULL END")
                    ->nullable()
                    ->after('student_id_if_confirmed');
            });

            Schema::table('workstation_requests', function (Blueprint $table) {
                $table->unique('workstation_id_if_confirmed');
            });
        }

        if (! Schema::hasColumn('workstation_locations', 'layout_image_path')) {
            Schema::table('workstation_locations', function (Blueprint $table) {
                $table->string('layout_image_path', 255)->nullable();
                $table->string('layout_image_mime', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('workstation_locations', function (Blueprint $table) {
            $table->dropColumn(['layout_image_path', 'layout_image_mime']);
        });

        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->dropUnique(['workstation_id_if_confirmed']);
            $table->dropColumn('workstation_id_if_confirmed');
        });

        Schema::dropIfExists('workstation_seat_counter');

        Schema::table('workstations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
