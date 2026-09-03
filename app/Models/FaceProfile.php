<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class FaceProfile extends Model
{
    use HasFactory, Auditable;

    protected $table = 'face_profile';
    protected $primaryKey = 'face_profile_id';
    public $incrementing = true;

    protected $fillable = [
        'directory_id',
        'embedding',
        'face_image',
        'face_version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(UserDirectory::class, 'directory_id', 'directory_id');
    }

    /**
     * Per-pose (FRONT/LEFT/RIGHT) biometric samples. A profile with no rows
     * here is a legacy/partial enrollment - matching falls back to this
     * profile's own `embedding` column as its implicit single pose.
     */
    public function embeddings(): HasMany
    {
        return $this->hasMany(FaceProfileEmbedding::class, 'face_profile_id', 'face_profile_id');
    }
}
