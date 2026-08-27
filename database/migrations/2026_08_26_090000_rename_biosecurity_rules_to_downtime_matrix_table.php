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
        Schema::rename('biosecurity_rules', 'downtime_matrix');

        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->dropColumn('area_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downtime_matrix', function (Blueprint $table) {
            $table->string('area_type', 100)->nullable();
        });

        Schema::rename('downtime_matrix', 'biosecurity_rules');
    }
};
