<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_entry_logs', function (Blueprint $table) {
            $table->string('authentication_method', 10)->default('FACE')->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_entry_logs', function (Blueprint $table) {
            $table->dropColumn('authentication_method');
        });
    }
};
