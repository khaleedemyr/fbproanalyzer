<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentBrief extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'status',
        'engine',
        'source_topic',
        'series_title',
        'episode_number',
        'parent_blueprint_id',
        'previous_episodes',
        'trend_signal_ids',
        'angle_finding_id',
        'angle_indexes',
        'analytics_ai_analysis_id',
        'inputs_snapshot',
        'resume',
        'resume_markdown',
        'content_blueprint_id',
        'warning',
    ];

    protected function casts(): array
    {
        return [
            'trend_signal_ids' => 'array',
            'angle_indexes' => 'array',
            'inputs_snapshot' => 'array',
            'resume' => 'array',
            'previous_episodes' => 'array',
            'episode_number' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function angleFinding(): BelongsTo
    {
        return $this->belongsTo(AngleFinding::class);
    }

    public function analyticsAiAnalysis(): BelongsTo
    {
        return $this->belongsTo(AnalyticsAiAnalysis::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ContentBlueprint::class, 'content_blueprint_id');
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(ContentBlueprint::class);
    }

    public function parentBlueprint(): BelongsTo
    {
        return $this->belongsTo(ContentBlueprint::class, 'parent_blueprint_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toBriefArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'engine' => $this->engine,
            'source_topic' => $this->source_topic,
            'series_title' => $this->series_title,
            'episode_number' => $this->episode_number,
            'parent_blueprint_id' => $this->parent_blueprint_id,
            'previous_episodes' => $this->previous_episodes ?? [],
            'trend_signal_ids' => $this->trend_signal_ids ?? [],
            'angle_finding_id' => $this->angle_finding_id,
            'angle_indexes' => $this->angle_indexes ?? [],
            'analytics_ai_analysis_id' => $this->analytics_ai_analysis_id,
            'inputs_snapshot' => $this->inputs_snapshot ?? [],
            'resume' => $this->resume ?? [],
            'resume_markdown' => $this->resume_markdown,
            'content_blueprint_id' => $this->content_blueprint_id,
            'warning' => $this->warning,
            'created_at' => optional($this->created_at)?->toDateTimeString(),
            'updated_at' => optional($this->updated_at)?->toDateTimeString(),
        ];
    }
}
