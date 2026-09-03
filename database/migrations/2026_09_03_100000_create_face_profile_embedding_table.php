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
        Schema::create('face_profile_embedding', function (Blueprint $table) {
            $table->id('face_profile_embedding_id');
            $table->foreignId('face_profile_id')->constrained('face_profile', 'face_profile_id')->cascadeOnDelete();
            $table->string('pose', 10);
            $table->text('embedding');
            $table->string('face_image', 255)->nullable();
            $table->string('face_version', 50)->nullable();
            $table->timestamps();

            $table->unique(['face_profile_id', 'pose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('face_profile_embedding');
    }
};
