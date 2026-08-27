<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_list', function (Blueprint $table) {
            $table->id('facility_id');
            $table->foreignId('facility_type_id')->constrained('facility_type', 'facility_type_id');
            $table->foreignId('facility_category_id')->constrained('facility_category', 'facility_category_id');
            $table->string('facility_code', 50)->unique();
            $table->string('facility_name', 150);
            $table->string('location', 255)->nullable();
            $table->boolean('is_rtl')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_list');
    }
};
