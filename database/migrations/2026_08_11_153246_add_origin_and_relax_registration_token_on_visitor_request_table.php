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
        Schema::table('visitor_request', function (Blueprint $table) {
            $table->text('origin')->nullable()->after('purpose');
            $table->string('registration_token', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor_request', function (Blueprint $table) {
            $table->dropColumn('origin');
            $table->string('registration_token', 100)->nullable(false)->change();
        });
    }
};
