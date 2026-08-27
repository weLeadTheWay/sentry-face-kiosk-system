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
        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->decimal('minimum_downtime', 6, 2)->nullable()->change();
            $table->decimal('maximum_downtime', 6, 2)->nullable()->change();
            $table->unique(['origin_farm_id', 'destination_farm_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->dropUnique(['origin_farm_id', 'destination_farm_id']);
            $table->integer('minimum_downtime')->nullable()->change();
            $table->integer('maximum_downtime')->nullable()->change();
        });
    }
};
