<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DowntimeMatrixImport extends Model
{
    use HasFactory, Auditable;

    protected $table = 'downtime_matrix_imports';
    protected $primaryKey = 'import_id';
    public $incrementing = true;

    protected $fillable = [
        'matrix_type',
        'original_filename',
        'stored_file_path',
        'status',
        'total_rows_parsed',
        'valid_rows_count',
        'warning_rows_count',
        'unmatched_rows_count',
        'ambiguous_rows_count',
        'invalid_rows_count',
        'parse_error_message',
        'uploaded_by',
        'verified_by',
        'verified_at',
        'cancelled_by',
        'cancelled_at',
        'promoted_by',
        'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DowntimeMatrixImportRow::class, 'import_id', 'import_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by', 'user_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by', 'user_id');
    }

    public function isPendingVerification(): bool
    {
        return $this->status === 'PENDING_VERIFICATION';
    }

    public function isVerified(): bool
    {
        return $this->status === 'VERIFIED';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'CANCELLED';
    }

    public function isPromoted(): bool
    {
        return $this->status === 'PROMOTED';
    }

    public function hasParseError(): bool
    {
        return $this->parse_error_message !== null;
    }
}
