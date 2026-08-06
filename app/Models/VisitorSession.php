<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\Auditable;

class VisitorSession extends Model
{
    use HasFactory, Auditable;

    protected $table = 'visitor_session';
    protected $primaryKey = 'visitor_session_id';
    public $incrementing = true;

    protected $fillable = [
        'visitor_request_id',
        'session_status',
        'login_id',
        'logout_id',
        'first_in',
        'last_out',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function visitorRequest(): BelongsTo
    {
        return $this->belongsTo(VisitorRequest::class, 'visitor_request_id', 'visitor_request_id');
    }

    public function entryLogs(): HasMany
    {
        return $this->hasMany(VisitorEntryLog::class, 'visitor_session_id', 'visitor_session_id');
    }

    /**
     * Login ID and Logout ID are generated fresh per visit (not per person)
     * and must not collide with each other or across sessions. $column is
     * whichever of 'login_id'/'logout_id' is being generated.
     */
    public static function generateSessionCode(string $column): string
    {
        for ($i = 0; $i < 3; $i++) {
            $code = Str::upper(Str::random(8));
            if (!self::where('login_id', $code)->exists() && !self::where('logout_id', $code)->exists()) {
                return $code;
            }
        }
        throw new \Exception("Failed to generate unique {$column} after 3 attempts");
    }
}
