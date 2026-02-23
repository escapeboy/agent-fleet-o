<?php

namespace App\Infrastructure\AI\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SemanticCacheEntry extends Model
{
    use HasUuids;

    protected $table = 'semantic_cache_entries';

    protected $fillable = [
        'team_id',
        'provider',
        'model',
        'prompt_hash',
        'request_text',
        'response_content',
        'response_metadata',
        'embedding',
        'hit_count',
        'expires_at',
    ];

    protected $casts = [
        'response_metadata' => 'array',
        'hit_count' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
