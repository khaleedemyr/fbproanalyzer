<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'meta_id',
    'name',
    'username',
    'type',
    'niche',
    'category',
    'picture_url',
    'access_token',
    'token_expires_at',
    'is_primary',
    'last_synced_at',
])]
#[Hidden(['access_token'])]
class MetaAccount extends Model
{
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_primary' => 'boolean',
            'access_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ContentPost::class);
    }
}
