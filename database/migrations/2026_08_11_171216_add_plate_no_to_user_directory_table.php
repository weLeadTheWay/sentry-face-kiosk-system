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
        Schema::table('user_directory', function (Blueprint $table) {
            $table->string('plate_no', 50)->nullable()->after('company');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_directory', function (Blueprint $table) {
            $table->dropColumn('plate_no');
        });
    }
};
