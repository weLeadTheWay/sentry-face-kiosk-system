<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_aliases', function (Blueprint $table) {
            $table->id('alias_id');
            $table->string('alias_text', 150)->unique();
            $table->foreignId('farm_id')->constrained('farm_list', 'farm_id')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_aliases');
    }
};
