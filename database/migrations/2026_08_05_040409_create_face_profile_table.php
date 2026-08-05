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
        Schema::create('face_profile', function (Blueprint $table) {
            $table->id('face_profile_id');
            $table->foreignId('directory_id')->constrained('user_directory', 'directory_id')->cascadeOnDelete();
            $table->text('embedding');
            $table->string('face_image', 255)->nullable();
            $table->string('face_version', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('face_profile');
    }
};
