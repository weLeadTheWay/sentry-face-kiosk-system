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
        Schema::create('visitor_entry_logs', function (Blueprint $table) {
            $table->id('visitor_log_id');
            $table->foreignId('visitor_session_id')->constrained('visitor_session', 'visitor_session_id')->cascadeOnDelete();
            $table->foreignId('kiosk_id')->constrained('kiosk_device', 'kiosk_id')->restrictOnDelete();
            $table->string('movement_type', 50);
            $table->string('action', 10);
            $table->text('remarks')->nullable();
            $table->string('photo', 255)->nullable();
            $table->dateTime('datetime')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_entry_logs');
    }
};
