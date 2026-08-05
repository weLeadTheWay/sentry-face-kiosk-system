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
        Schema::create('visitor_session', function (Blueprint $table) {
            $table->id('visitor_session_id');
            $table->foreignId('visitor_request_id')->constrained('visitor_request', 'visitor_request_id')->cascadeOnDelete();
            $table->string('session_status', 50)->default('OPEN');
            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_session');
    }
};
