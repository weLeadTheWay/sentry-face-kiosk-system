<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_matrix_import_rows', function (Blueprint $table) {
            $table->id('import_row_id');
            $table->foreignId('import_id')->constrained('downtime_matrix_imports', 'import_id')->onDelete('cascade');

            $table->string('rule_type', 20);

            $table->string('origin_raw_label', 255);
            $table->string('destination_raw_label', 255);

            $table->foreignId('origin_facility_id')->nullable()->constrained('facility_list', 'facility_id')->nullOnDelete();
            $table->foreignId('destination_facility_id')->nullable()->constrained('facility_list', 'facility_id')->nullOnDelete();

            $table->string('origin_resolution_method', 30)->nullable();
            $table->string('destination_resolution_method', 30)->nullable();
            $table->string('origin_facility_group_category', 50)->nullable();
            $table->string('destination_facility_group_category', 50)->nullable();

            $table->decimal('downtime_area_hours', 6, 2)->nullable();
            $table->decimal('dormitory_hours', 6, 2)->nullable();
            $table->decimal('minimum_downtime', 6, 2)->nullable();
            $table->decimal('maximum_downtime', 6, 2)->nullable();

            $table->decimal('clean_downtime_area_hours', 6, 2)->nullable();
            $table->decimal('clean_dormitory_hours', 6, 2)->nullable();
            $table->decimal('restricted_downtime_area_hours', 6, 2)->nullable();
            $table->decimal('restricted_dormitory_hours', 6, 2)->nullable();

            $table->string('resolution_status', 20);
            $table->text('validation_message')->nullable();
            $table->unsignedInteger('page_number')->default(1);

            $table->timestamps();

            $table->index(['import_id', 'rule_type'], 'dmir_import_rule_type_idx');
            $table->index(['import_id', 'resolution_status'], 'dmir_import_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('downtime_matrix_import_rows', function (Blueprint $table) {
            $table->dropIndex('dmir_import_rule_type_idx');
            $table->dropIndex('dmir_import_status_idx');
        });
        Schema::dropIfExists('downtime_matrix_import_rows');
    }
};
