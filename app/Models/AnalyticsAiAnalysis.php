<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AnalyticsAiAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'engine',
        'title',
        'diagnosis',
        'whats_working',
        'whats_blocking',
        'million_view_playbook',
        'next_7_days',
        'title_hooks',
        'avoid',
        'snapshot_meta',
    ];

    protected function casts(): array
    {
        return [
            'whats_working' => 'array',
            'whats_blocking' => 'array',
            'million_view_playbook' => 'array',
            'next_7_days' => 'array',
            'title_hooks' => 'array',
            'avoid' => 'array',
            'snapshot_meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(ContentBlueprint::class);
    }

    /**
     * Compact payload for API list/detail + blueprint reference.
     *
     * @return array<string, mixed>
     */
    public function toAnalysisArray(): array
    {
        return [
            'id' => $this->id,
            'engine' => $this->engine,
            'title' => $this->title,
            'diagnosis' => $this->diagnosis,
            'whats_working' => $this->whats_working ?? [],
            'whats_blocking' => $this->whats_blocking ?? [],
            'million_view_playbook' => $this->million_view_playbook ?? [],
            'next_7_days' => $this->next_7_days ?? [],
            'title_hooks' => $this->title_hooks ?? [],
            'avoid' => $this->avoid ?? [],
            'snapshot_meta' => $this->snapshot_meta ?? [],
            'created_at' => optional($this->created_at)?->toDateTimeString(),
        ];
    }

    public static function makeTitleFromDiagnosis(?string $diagnosis, string $engine): string
    {
        $base = trim((string) $diagnosis);
        if ($base === '') {
            return 'Analisa AI · '.now()->format('d M Y H:i');
        }

        return Str::limit($base, 90, '…').' · '.strtoupper($engine);
    }
}
