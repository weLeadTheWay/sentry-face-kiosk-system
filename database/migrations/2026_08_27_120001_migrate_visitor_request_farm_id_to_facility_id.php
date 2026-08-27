<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * farm_id -> facility_id, for the 8 farms that already exist as
     * facility_list rows (Phase 1 seed). facility_id could not be made equal
     * to farm_id - Phase 1 already assigned these same 8 names sequential
     * IDs 1-8 alongside 8 unrelated non-farm facilities at IDs 9-16. See
     * CLAUDE.md's "Facility Master Data" module for the approved mapping.
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
        Schema::table('visitor_request', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->after('farm_id')
                ->constrained('facility_list', 'facility_id')->restrictOnDelete();
        });

        foreach ($this->farmIdToFacilityId as $farmId => $facilityId) {
            DB::table('visitor_request')->where('farm_id', $farmId)->update(['facility_id' => $facilityId]);
        }

        // Native change() (not raw SQL) - MODIFY is MySQL-only syntax and
        // this must also run against the sqlite :memory: DB the test suite
        // uses; Laravel 11+ handles column alteration natively (including a
        // full-table rebuild on sqlite) without a doctrine/dbal dependency.
        Schema::table('visitor_request', function (Blueprint $table) {
            $table->unsignedBigInteger('facility_id')->nullable(false)->change();
        });

        Schema::table('visitor_request', function (Blueprint $table) {
            $table->dropConstrainedForeignId('farm_id');
        });
    }

    public function down(): void
    {
        $facilityIdToFarmId = array_flip($this->farmIdToFacilityId);

        Schema::table('visitor_request', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable()->after('directory_id')
                ->constrained('farm_list', 'farm_id')->restrictOnDelete();
        });

        foreach ($facilityIdToFarmId as $facilityId => $farmId) {
            DB::table('visitor_request')->where('facility_id', $facilityId)->update(['farm_id' => $farmId]);
        }

        Schema::table('visitor_request', function (Blueprint $table) {
            $table->unsignedBigInteger('farm_id')->nullable(false)->change();
        });

        Schema::table('visitor_request', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_id');
        });
    }
};
