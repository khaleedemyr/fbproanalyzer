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
        'analytics_ai_analysis_id',
        'content_brief_id',
        'angle_finding_id',
        'title',
        'cover_theme',
        'story_concept',
        'source_topic',
        'series_title',
        'episode_number',
        'parent_blueprint_id',
        'previous_episodes',
        'status',
        'format',
        'engine',
        'duration_seconds',
        'beat_seconds',
        'vo_beat_seconds',
        'prefer_stock',
        'voice_perspective',
        'scenes',
        'thumbnail_prompt',
        'caption',
        'title_options',
        'planned_date',
        'planned_time',
    ];

    protected function casts(): array
    {
        return [
            'scenes' => 'array',
            'title_options' => 'array',
            'previous_episodes' => 'array',
            'planned_date' => 'date',
            'prefer_stock' => 'boolean',
            'duration_seconds' => 'integer',
            'beat_seconds' => 'integer',
            'vo_beat_seconds' => 'integer',
            'episode_number' => 'integer',
        ];
    }

    public function plannedTimeHm(): ?string
    {
        $t = $this->planned_time;
        if ($t === null || $t === '') {
            return null;
        }

        return substr((string) $t, 0, 5);
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

    public function analyticsAiAnalysis(): BelongsTo
    {
        return $this->belongsTo(AnalyticsAiAnalysis::class);
    }

    public function contentBrief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class);
    }

    public function angleFinding(): BelongsTo
    {
        return $this->belongsTo(AngleFinding::class);
    }

    public function parentBlueprint(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_blueprint_id');
    }
}
