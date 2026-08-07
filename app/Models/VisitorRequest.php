<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class VisitorRequest extends Model
{
    use HasFactory, Auditable;

    protected $table = 'visitor_request';
    protected $primaryKey = 'visitor_request_id';
    public $incrementing = true;

    protected $fillable = [
        'directory_id',
        'visitor_id',
        'qr_url',
        'farm_id',
        'host_name',
        'purpose',
        'visit_datetime',
        'departure_datetime',
        'registration_token',
        'approval_status',
        'request_status',
        'face_registration_status',
        'manual_verification_required',
    ];

    protected function casts(): array
    {
        return [
            'visit_datetime' => 'datetime',
            'departure_datetime' => 'datetime',
            'manual_verification_required' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * A visitor request is valid "today" if approved and today falls within
     * [visit date, departure date] inclusive (departure date defaults to the
     * visit date itself for single-day visits with no explicit departure).
     */
    public function scopeActiveToday(Builder $query, ?string $date = null): Builder
    {
        $date ??= now()->toDateString();

        return $query->where('approval_status', 'Approved')
            ->whereDate('visit_datetime', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->where(function (Builder $q2) use ($date) {
                    $q2->whereNotNull('departure_datetime')
                        ->whereDate('departure_datetime', '>=', $date);
                })->orWhere(function (Builder $q2) use ($date) {
                    $q2->whereNull('departure_datetime')
                        ->whereDate('visit_datetime', $date);
                });
            });
    }

    private const TERMINAL_STATUSES = ['COMPLETED', 'COMPLETED_AUTO', 'INCOMPLETE'];

    /**
     * Any of these means the request is closed and must never be reused:
     * COMPLETED (manual Final Exit), COMPLETED_AUTO / INCOMPLETE (auto-resolved
     * by the expired-session scheduler). Callers that only care about "is this
     * blocked from further action" should use this rather than comparing
     * request_status to a single literal value.
     */
    public function isCompleted(): bool
    {
        return in_array($this->request_status, self::TERMINAL_STATUSES, true);
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(UserDirectory::class, 'directory_id', 'directory_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'farm_id', 'farm_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VisitorSession::class, 'visitor_request_id', 'visitor_request_id');
    }
}
