<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'meta_account_id',
    'meta_post_id',
    'permalink',
    'format',
    'message',
    'media_url',
    'published_at',
    'reach',
    'impressions',
    'likes',
    'comments',
    'shares',
    'saves',
    'engagement_rate',
    'viral_score',
    'topic_cluster',
    'raw_insights',
])]
class ContentPost extends Model
{
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'raw_insights' => 'array',
            'engagement_rate' => 'decimal:4',
            'viral_score' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function metaAccount(): BelongsTo
    {
        return $this->belongsTo(MetaAccount::class);
    }
}
