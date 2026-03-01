<?php

namespace App\Infrastructure\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class SemanticCacheEntry extends Model
{
    use HasUuids, MassPrunable;

    public function prunable(): Builder
    {
        // Remove expired entries and anything older than 90 days
        return static::where(function (Builder $q) {
            $q->whereNotNull('expires_at')->where('expires_at', '<', now());
        })->orWhere('created_at', '<', now()->subDays(90));
    }

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
