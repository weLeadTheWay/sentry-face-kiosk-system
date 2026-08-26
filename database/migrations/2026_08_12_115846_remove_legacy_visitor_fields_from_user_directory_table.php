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
            $table->dropForeign(['visitor_type_id']);
            $table->dropColumn(['visitor_type_id', 'company', 'plate_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_directory', function (Blueprint $table) {
            $table->foreignId('visitor_type_id')->nullable()->constrained('visitor_type', 'visitor_type_id')->nullOnDelete();
            $table->string('company', 255)->nullable();
            $table->string('plate_no', 50)->nullable();
        });
    }
};
