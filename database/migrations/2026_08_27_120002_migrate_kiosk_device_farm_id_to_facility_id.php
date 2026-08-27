<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same farm_id -> facility_id mapping as
     * 2026_08_27_120001_migrate_visitor_request_farm_id_to_facility_id - see
     * that migration and CLAUDE.md's "Facility Master Data" module for why
     * these values (not farm_id verbatim) are the correct facility_id ones.
     */
    private array $farmIdToFacilityId = [
        1 => 5,   // Madera
        3 => 7,   // San Pascual
        4 => 8,   // Victory
        5 => 6,   // Rosemary
        7 => 3,   // Cinnamon
        10 => 1,  // Saturn
        11 => 2,  // Venus
        12 => 4,  // Mars
    ];

    public function up(): void
    {
        Schema::table('kiosk_device', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->after('farm_id')
                ->constrained('facility_list', 'facility_id')->cascadeOnDelete();
        });

        foreach ($this->farmIdToFacilityId as $farmId => $facilityId) {
            DB::table('kiosk_device')->where('farm_id', $farmId)->update(['facility_id' => $facilityId]);
        }

        // Native change() (not raw SQL) - MODIFY is MySQL-only syntax and
        // this must also run against the sqlite :memory: DB the test suite
        // uses; Laravel 11+ handles column alteration natively (including a
        // full-table rebuild on sqlite) without a doctrine/dbal dependency.
        Schema::table('kiosk_device', function (Blueprint $table) {
            $table->unsignedBigInteger('facility_id')->nullable(false)->change();
        });

        Schema::table('kiosk_device', function (Blueprint $table) {
            $table->dropConstrainedForeignId('farm_id');
        });
    }

    public function down(): void
    {
        $facilityIdToFarmId = array_flip($this->farmIdToFacilityId);

        Schema::table('kiosk_device', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable()->after('kiosk_id')
                ->constrained('farm_list', 'farm_id')->cascadeOnDelete();
        });

        foreach ($facilityIdToFarmId as $facilityId => $farmId) {
            DB::table('kiosk_device')->where('facility_id', $facilityId)->update(['farm_id' => $farmId]);
        }

        Schema::table('kiosk_device', function (Blueprint $table) {
            $table->unsignedBigInteger('farm_id')->nullable(false)->change();
        });

        Schema::table('kiosk_device', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_id');
        });
    }
};
