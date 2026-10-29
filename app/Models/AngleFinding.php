<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AngleFinding extends Model
{
    protected $fillable = [
        'user_id',
        'topic',
        'engine',
        'videos_scanned',
        'comments_scanned',
        'source_videos',
        'pain_points',
        'angles',
        'sample_comments',
        'summary',
        'warning',
    ];

    protected function casts(): array
    {
        return [
            'source_videos' => 'array',
            'pain_points' => 'array',
            'angles' => 'array',
            'sample_comments' => 'array',
            'videos_scanned' => 'integer',
            'comments_scanned' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
