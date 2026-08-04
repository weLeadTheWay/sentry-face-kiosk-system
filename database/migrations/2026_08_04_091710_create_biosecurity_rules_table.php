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
        Schema::create('biosecurity_rules', function (Blueprint $table) {
            $table->id('rule_id');
            $table->foreignId('origin_farm_id')->constrained('farm_list', 'farm_id')->onDelete('cascade');
            $table->foreignId('destination_farm_id')->constrained('farm_list', 'farm_id')->onDelete('cascade');
            $table->string('area_type', 100)->nullable();
            $table->integer('minimum_downtime')->nullable();
            $table->integer('maximum_downtime')->nullable();
            $table->string('access_level', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biosecurity_rules');
    }
};
