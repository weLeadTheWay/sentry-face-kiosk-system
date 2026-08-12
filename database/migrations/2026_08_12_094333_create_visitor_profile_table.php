<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('visitor_profile', function (Blueprint $table) {
            $table->id('visitor_profile_id');
            $table->foreignId('directory_id')->unique()->constrained('user_directory', 'directory_id')->cascadeOnDelete();
            $table->foreignId('visitor_type_id')->nullable()->constrained('visitor_type', 'visitor_type_id')->nullOnDelete();
            $table->string('company', 255)->nullable();
            $table->string('plate_no', 50)->nullable();
            $table->timestamps();
        });

        // Backfill from existing data. Uses the query builder (not raw SQL
        // or Eloquent) so it behaves identically on both MySQL (dev/prod)
        // and SQLite (tests). visitor_profile is initially just a
        // synchronized copy - user_directory's old columns remain the
        // application's source of truth until a later step migrates each
        // read/write path.
        DB::table('user_directory')
            ->whereNotNull('visitor_type_id')
            ->orderBy('directory_id')
            ->chunkById(500, function ($directories) {
                $now = now();
                $rows = $directories->map(fn ($d) => [
                    'directory_id' => $d->directory_id,
                    'visitor_type_id' => $d->visitor_type_id,
                    'company' => $d->company,
                    'plate_no' => $d->plate_no,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('visitor_profile')->insert($rows);
            }, 'directory_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_profile');
    }
};
