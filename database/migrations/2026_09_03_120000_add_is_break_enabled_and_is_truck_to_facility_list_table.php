<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_list', function (Blueprint $table) {
            // Defaults true - unlike is_gs/is_truck, this preserves the
            // multi-break behavior every facility already has today; it is
            // an opt-out restriction, not an opt-in feature.
            $table->boolean('is_break_enabled')->default(true)->after('is_gs');
            $table->boolean('is_truck')->default(false)->after('is_break_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('facility_list', function (Blueprint $table) {
            $table->dropColumn(['is_break_enabled', 'is_truck']);
        });
    }
};
