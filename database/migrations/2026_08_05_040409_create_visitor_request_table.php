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
        Schema::create('visitor_request', function (Blueprint $table) {
            $table->id('visitor_request_id');
            $table->foreignId('directory_id')->constrained('user_directory', 'directory_id')->cascadeOnDelete();
            $table->string('visitor_id', 255)->unique()->nullable();
            $table->string('qr_url', 500)->nullable();
            $table->foreignId('farm_id')->constrained('farm_list', 'farm_id')->restrictOnDelete();
            $table->string('host_name', 255);
            $table->text('purpose')->nullable();
            $table->dateTime('visit_datetime');
            $table->dateTime('departure_datetime')->nullable();
            $table->string('registration_token', 100)->unique();
            $table->string('approval_status', 50)->default('Approved');
            $table->string('request_status', 50)->default('ACTIVE');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_request');
    }
};
