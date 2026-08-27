<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same farm_id -> facility_id mapping used by the sibling downtime_matrix
     * migration and the earlier visitor_request/kiosk_device cutover - see
     * CLAUDE.md's "Facility Master Data" module.
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
        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->foreignId('assigned_facility_id')->nullable()->after('assigned_farm_id')
                ->constrained('facility_list', 'facility_id')->cascadeOnDelete();
        });

        foreach ($this->farmIdToFacilityId as $farmId => $facilityId) {
            DB::table('downtime_stationary')->where('assigned_farm_id', $farmId)->update(['assigned_facility_id' => $facilityId]);
        }

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_facility_id')->nullable(false)->change();
        });

        // MySQL (InnoDB) refuses to drop a unique index while a FK still
        // relies on it ("Cannot drop index ... needed in a foreign key
        // constraint") - the FK must be dropped in its own prior statement,
        // before the unique index or the column. This FK constraint name IS
        // the default Laravel convention (unlike downtime_matrix's, which
        // survived the biosecurity_rules rename), so the column-array form
        // works on both drivers without a sqlite/mysql branch.
        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->dropForeign(['assigned_farm_id']);
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->dropUnique('downtime_stationary_assigned_farm_id_unique');
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->dropColumn('assigned_farm_id');
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->unique('assigned_facility_id');
        });
    }

    public function down(): void
    {
        $facilityIdToFarmId = array_flip($this->farmIdToFacilityId);

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->foreignId('assigned_farm_id')->nullable()->after('rule_id')
                ->constrained('farm_list', 'farm_id')->cascadeOnDelete();
        });

        foreach ($facilityIdToFarmId as $facilityId => $farmId) {
            DB::table('downtime_stationary')->where('assigned_facility_id', $facilityId)->update(['assigned_farm_id' => $farmId]);
        }

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_farm_id')->nullable(false)->change();
        });

        // Same MySQL ordering constraint as above: drop the FK on
        // assigned_facility_id (created via ->constrained() earlier with
        // Laravel's own naming convention) before the unique index that
        // references it.
        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->dropForeign(['assigned_facility_id']);
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->dropUnique(['assigned_facility_id']);
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->dropColumn('assigned_facility_id');
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->unique('assigned_farm_id');
        });
    }
};
