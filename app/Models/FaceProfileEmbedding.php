<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceProfileEmbedding extends Model
{
    use HasFactory;

    protected $table = 'face_profile_embedding';
    protected $primaryKey = 'face_profile_embedding_id';
    public $incrementing = true;

    protected $fillable = [
        'face_profile_id',
        'pose',
        'embedding',
        'face_image',
        'face_version',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function faceProfile(): BelongsTo
    {
        return $this->belongsTo(FaceProfile::class, 'face_profile_id', 'face_profile_id');
    }
}
