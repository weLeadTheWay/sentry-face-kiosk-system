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
        Schema::create('kiosk_device', function (Blueprint $table) {
            $table->id('kiosk_id');
            $table->foreignId('farm_id')->constrained('farm_list', 'farm_id')->onDelete('cascade');
            $table->string('device_name', 100);
            $table->string('device_type', 50)->nullable();
            $table->string('serial_number', 100)->unique();
            $table->string('public_ip', 45)->nullable();
            $table->string('status', 50)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kiosk_device');
    }
};
