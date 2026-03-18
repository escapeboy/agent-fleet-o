<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Knowledge\Enums\KnowledgeBaseStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property KnowledgeBaseStatus $status
 * @property Carbon|null $last_ingested_at
 */
class KnowledgeBase extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'agent_id',
        'name',
        'description',
        'status',
        'chunks_count',
        'last_ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeBaseStatus::class,
            'chunks_count' => 'integer',
            'last_ingested_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function isReady(): bool
    {
        return $this->status === KnowledgeBaseStatus::Ready;
    }

    public function markIngesting(): void
    {
        $this->update(['status' => KnowledgeBaseStatus::Ingesting]);
    }

    public function markReady(int $chunksCount): void
    {
        $this->update([
            'status' => KnowledgeBaseStatus::Ready,
            'chunks_count' => $chunksCount,
            'last_ingested_at' => now(),
        ]);
    }

    public function markError(): void
    {
        $this->update(['status' => KnowledgeBaseStatus::Error]);
    }
}
