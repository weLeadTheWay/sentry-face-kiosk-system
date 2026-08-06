<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_session', function (Blueprint $table) {
            $table->string('login_id', 50)->nullable()->after('session_status');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_session', function (Blueprint $table) {
            $table->dropColumn('login_id');
        });
    }
};
