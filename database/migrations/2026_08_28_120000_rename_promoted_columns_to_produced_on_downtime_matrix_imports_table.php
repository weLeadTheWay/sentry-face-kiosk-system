<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames promoted_by/promoted_at (added same-day, before the actual
 * Production mapping logic existed) to produced_by/produced_at - the
 * terminology settled on "Production"/"PRODUCED" once the real mapping
 * into downtime_matrix/downtime_stationary was specified, so the column
 * names now match the status value (PRODUCED) and the UI ("Save to
 * Production") instead of the earlier placeholder "promote" language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downtime_matrix_imports', function (Blueprint $table) {
            $table->renameColumn('promoted_by', 'produced_by');
            $table->renameColumn('promoted_at', 'produced_at');
        });

        DB::table('downtime_matrix_imports')->where('status', 'PROMOTED')->update(['status' => 'PRODUCED']);
    }

    public function down(): void
    {
        DB::table('downtime_matrix_imports')->where('status', 'PRODUCED')->update(['status' => 'PROMOTED']);

        Schema::table('downtime_matrix_imports', function (Blueprint $table) {
            $table->renameColumn('produced_by', 'promoted_by');
            $table->renameColumn('produced_at', 'promoted_at');
        });
    }
};
