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
        Schema::create('user_directory', function (Blueprint $table) {
            $table->id('directory_id');
            $table->foreignId('identity_type_id')->constrained('identity_type', 'identity_type_id')->restrictOnDelete();
            $table->string('person_reference', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('full_name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('employee_no', 100)->nullable();
            $table->foreignId('visitor_type_id')->nullable()->constrained('visitor_type', 'visitor_type_id')->nullOnDelete();
            $table->foreignId('employee_type_id')->nullable()->constrained('employee_type', 'employee_type_id')->nullOnDelete();
            $table->string('login_id', 8)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_directory');
    }
};
