<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_matrix_imports', function (Blueprint $table) {
            $table->id('import_id');
            $table->string('matrix_type', 20);
            $table->string('original_filename', 255);
            $table->string('stored_file_path', 255);
            $table->string('status', 30)->default('PENDING_VERIFICATION');
            $table->unsignedInteger('total_rows_parsed')->default(0);
            $table->unsignedInteger('valid_rows_count')->default(0);
            $table->unsignedInteger('warning_rows_count')->default(0);
            $table->unsignedInteger('unmatched_rows_count')->default(0);
            $table->unsignedInteger('ambiguous_rows_count')->default(0);
            $table->unsignedInteger('invalid_rows_count')->default(0);
            $table->text('parse_error_message')->nullable();
            $table->foreignId('uploaded_by')->constrained('users', 'user_id');
            $table->foreignId('verified_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_matrix_imports');
    }
};
