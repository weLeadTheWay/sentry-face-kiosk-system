<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same farm_id -> facility_id mapping used by the 2026_08_27_120001/120002
     * visitor_request/kiosk_device cutover migrations - see CLAUDE.md's
     * "Facility Master Data" module for why these values (not farm_id
     * verbatim) are the correct facility_id ones.
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
        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->foreignId('origin_facility_id')->nullable()->after('origin_farm_id')
                ->constrained('facility_list', 'facility_id')->cascadeOnDelete();
            $table->foreignId('destination_facility_id')->nullable()->after('destination_farm_id')
                ->constrained('facility_list', 'facility_id')->cascadeOnDelete();
        });

        foreach ($this->farmIdToFacilityId as $farmId => $facilityId) {
            DB::table('downtime_matrix')->where('origin_farm_id', $farmId)->update(['origin_facility_id' => $facilityId]);
            DB::table('downtime_matrix')->where('destination_farm_id', $farmId)->update(['destination_facility_id' => $facilityId]);
        }

        // Native change() (not raw SQL) - must also run against the sqlite
        // :memory: DB the test suite uses.
        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->unsignedBigInteger('origin_facility_id')->nullable(false)->change();
            $table->unsignedBigInteger('destination_facility_id')->nullable(false)->change();
        });

        // MySQL (InnoDB) refuses to drop the composite unique index while a
        // FK still relies on it ("Cannot drop index ... needed in a foreign
        // key constraint") - the FK constraints must be dropped in their own
        // prior statement, before the unique index or the columns.
        Schema::table('downtime_matrix', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                // SQLite's grammar can only drop a foreign key by column,
                // not by name (dropForeign() with a name string throws
                // "This database driver does not support dropping foreign
                // keys by name") - safe here since sqlite (the test suite's
                // driver) has no pre-existing constraint-name history to
                // match; each test run builds the schema fresh.
                $table->dropForeign(['origin_farm_id']);
                $table->dropForeign(['destination_farm_id']);
            } else {
                // MySQL: these FK constraint names are leftovers from the
                // 2026-08-26 biosecurity_rules -> downtime_matrix rename
                // (`biosecurity_rules_..._foreign`, NOT the
                // `downtime_matrix_..._foreign` that the column-array form
                // above would assume) - must drop by their actual name.
                $table->dropForeign('biosecurity_rules_origin_farm_id_foreign');
                $table->dropForeign('biosecurity_rules_destination_farm_id_foreign');
            }
        });

        // Now safe: no FK depends on this index anymore.
        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->dropUnique('downtime_matrix_origin_farm_id_destination_farm_id_unique');
        });

        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->dropColumn(['origin_farm_id', 'destination_farm_id']);
        });

        Schema::table('downtime_matrix', function (Blueprint $table) {
            // Explicit, shorter name - Laravel's auto-generated name
            // ("downtime_matrix_origin_facility_id_destination_facility_id_
            // unique") exceeds MySQL's 64-character identifier limit.
            $table->unique(['origin_facility_id', 'destination_facility_id'], 'downtime_matrix_origin_dest_facility_unique');
        });
    }

    public function down(): void
    {
        $facilityIdToFarmId = array_flip($this->farmIdToFacilityId);

        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->foreignId('origin_farm_id')->nullable()->after('rule_id')
                ->constrained('farm_list', 'farm_id')->cascadeOnDelete();
            $table->foreignId('destination_farm_id')->nullable()->after('origin_facility_id')
                ->constrained('farm_list', 'farm_id')->cascadeOnDelete();
        });

        foreach ($facilityIdToFarmId as $facilityId => $farmId) {
            DB::table('downtime_matrix')->where('origin_facility_id', $facilityId)->update(['origin_farm_id' => $farmId]);
            DB::table('downtime_matrix')->where('destination_facility_id', $facilityId)->update(['destination_farm_id' => $farmId]);
        }

        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->unsignedBigInteger('origin_farm_id')->nullable(false)->change();
            $table->unsignedBigInteger('destination_farm_id')->nullable(false)->change();
        });

        // Same MySQL ordering constraint as up(): drop the FKs (on the
        // facility columns, both created via ->constrained() above with
        // Laravel's own naming convention, so dropConstrainedForeignId()
        // is safe here on both drivers) before the unique index that
        // references those same columns.
        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->dropForeign(['origin_facility_id']);
            $table->dropForeign(['destination_facility_id']);
        });

        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->dropUnique('downtime_matrix_origin_dest_facility_unique');
        });

        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->dropColumn(['origin_facility_id', 'destination_facility_id']);
        });

        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->unique(['origin_farm_id', 'destination_farm_id']);
        });
    }
};
