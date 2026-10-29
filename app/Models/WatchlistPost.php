<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'watchlist_account_id',
    'title',
    'caption',
    'post_url',
    'format',
    'likes',
    'comments',
    'shares',
    'estimated_score',
    'hook_note',
    'observed_at',
])]
class WatchlistPost extends Model
{
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'estimated_score' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WatchlistAccount::class, 'watchlist_account_id');
    }
}
