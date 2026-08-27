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
        Schema::create('downtime_stationary', function (Blueprint $table) {
            $table->id('rule_id');
            $table->foreignId('assigned_farm_id')->constrained('farm_list', 'farm_id')->onDelete('cascade');
            $table->decimal('minimum_downtime_hours', 5, 2)->nullable();
            $table->decimal('max_downtime_hours', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('downtime_stationary');
    }
};
