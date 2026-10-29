<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'content_post_id',
        'platform',
        'external_id',
        'snapshot_date',
        'views',
        'impressions',
        'reach',
        'likes',
        'comments',
        'shares',
        'saves',
        'avg_watch_seconds',
        'retention_rate',
        'engagement_rate',
        'viral_score',
        'delta_views',
        'delta_shares',
        'format',
        'topic_cluster',
        'formula_key',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'raw' => 'array',
            'avg_watch_seconds' => 'decimal:2',
            'retention_rate' => 'decimal:2',
            'engagement_rate' => 'decimal:4',
            'viral_score' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contentPost(): BelongsTo
    {
        return $this->belongsTo(ContentPost::class);
    }
}
