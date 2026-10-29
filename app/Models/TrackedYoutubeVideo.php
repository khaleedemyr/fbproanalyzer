<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackedYoutubeVideo extends Model
{
    protected $fillable = [
        'user_id',
        'video_id',
        'title',
        'channel',
        'url',
        'formula_tag',
        'format',
        'views',
        'likes',
        'comments',
        'published_at',
        'last_synced_at',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
