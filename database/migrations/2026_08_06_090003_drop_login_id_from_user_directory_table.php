<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * login_id is no longer a permanent per-person identifier - it (and its
     * counterpart logout_id) are now generated fresh per visit and live on
     * visitor_session instead. See 2026_08_06_090004_add_logout_id_to_visitor_session_table.
     */
    public function up(): void
    {
        // SQLite can't drop a column with a dependent unique index in the
        // same statement (used by the test suite) - drop the index first,
        // as a separate step, so this works on both SQLite and MySQL.
        Schema::table('user_directory', function (Blueprint $table) {
            $table->dropUnique(['login_id']);
        });

        Schema::table('user_directory', function (Blueprint $table) {
            $table->dropColumn('login_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_directory', function (Blueprint $table) {
            $table->string('login_id', 8)->unique()->nullable();
        });
    }
};
