<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KioskDevice extends Model
{
    use HasFactory, Auditable;

    protected $table = 'kiosk_device';
    protected $primaryKey = 'kiosk_id';
    public $incrementing = true;

    protected $fillable = [
        'farm_id',
        'device_name',
        'device_type',
        'serial_number',
        'public_ip',
        'status',
        'last_seen_at',
        'kiosk_token',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(FarmList::class, 'farm_id', 'farm_id');
    }

    protected static function booting()
    {
        static::creating(function (self $model) {
            if (!$model->kiosk_token) {
                $model->kiosk_token = self::generateKioskToken();
            }
        });
    }

    public function regenerateToken(): self
    {
        $this->kiosk_token = self::generateKioskToken();
        $this->save();
        return $this;
    }

    private static function generateKioskToken(): string
    {
        for ($i = 0; $i < 3; $i++) {
            $token = 'KIOSK_' . Str::upper(Str::random(32));
            if (!self::where('kiosk_token', $token)->exists()) {
                return $token;
            }
        }
        throw new \Exception('Failed to generate unique kiosk_token after 3 attempts');
    }
}
