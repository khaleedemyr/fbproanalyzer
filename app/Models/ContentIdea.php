<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'meta_account_id',
    'title',
    'angle',
    'format',
    'hook',
    'topic_cluster',
    'potential_score',
    'status',
    'notes',
])]
class ContentIdea extends Model
{
    protected function casts(): array
    {
        return [
            'potential_score' => 'decimal:2',
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
