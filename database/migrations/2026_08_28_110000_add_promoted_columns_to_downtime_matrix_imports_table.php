<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downtime_matrix_imports', function (Blueprint $table) {
            $table->foreignId('promoted_by')->nullable()->after('cancelled_at')->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('promoted_at')->nullable()->after('promoted_by');
        });
    }

    public function down(): void
    {
        Schema::table('downtime_matrix_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promoted_by');
            $table->dropColumn('promoted_at');
        });
    }
};
