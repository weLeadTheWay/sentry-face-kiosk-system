<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->renameColumn('minimum_downtime_hours', 'minimum_downtime');
            $table->renameColumn('max_downtime_hours', 'maximum_downtime');
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->decimal('minimum_downtime', 6, 2)->nullable()->change();
            $table->decimal('maximum_downtime', 6, 2)->nullable()->change();
            $table->unique('assigned_farm_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->dropUnique(['assigned_farm_id']);
            $table->decimal('minimum_downtime', 5, 2)->nullable()->change();
            $table->decimal('maximum_downtime', 5, 2)->nullable()->change();
        });

        Schema::table('downtime_stationary', function (Blueprint $table) {
            $table->renameColumn('minimum_downtime', 'minimum_downtime_hours');
            $table->renameColumn('maximum_downtime', 'max_downtime_hours');
        });
    }
};
