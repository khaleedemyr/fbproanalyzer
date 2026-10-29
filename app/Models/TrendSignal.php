<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'fingerprint',
    'source',
    'niche',
    'keyword',
    'title',
    'snippet',
    'url',
    'format',
    'metric_value',
    'viral_score',
    'region',
    'raw',
    'observed_at',
])]
class TrendSignal extends Model
{
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'observed_at' => 'datetime',
            'viral_score' => 'decimal:2',
        ];
    }
}
