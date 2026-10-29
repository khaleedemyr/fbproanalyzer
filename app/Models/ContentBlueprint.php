<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBlueprint extends Model
{
    protected $fillable = [
        'user_id',
        'brand_kit_id',
        'content_idea_id',
        'trend_signal_id',
        'title',
        'cover_theme',
        'story_concept',
        'source_topic',
        'status',
        'format',
        'engine',
        'duration_seconds',
        'beat_seconds',
        'prefer_stock',
        'scenes',
        'thumbnail_prompt',
        'caption',
        'title_options',
        'planned_date',
    ];

    protected function casts(): array
    {
        return [
            'scenes' => 'array',
            'title_options' => 'array',
            'planned_date' => 'date',
            'prefer_stock' => 'boolean',
            'duration_seconds' => 'integer',
            'beat_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }

    public function contentIdea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class);
    }

    public function trendSignal(): BelongsTo
    {
        return $this->belongsTo(TrendSignal::class);
    }
}
