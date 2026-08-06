<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_request', function (Blueprint $table) {
            $table->string('face_registration_status', 20)->default('PENDING')->after('request_status');
            $table->boolean('manual_verification_required')->default(false)->after('face_registration_status');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_request', function (Blueprint $table) {
            $table->dropColumn(['face_registration_status', 'manual_verification_required']);
        });
    }
};
