<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubeAccount extends Model
{
    protected $fillable = [
        'user_id',
        'google_user_id',
        'channel_id',
        'channel_title',
        'email',
        'picture_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'last_synced_at',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }
}
