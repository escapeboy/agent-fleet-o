<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UncertaintySignal extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'experiment_stage_id',
        'signal_text',
        'context',
        'status',
        'ttl_minutes',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'ttl_minutes' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function experimentStage(): BelongsTo
    {
        return $this->belongsTo(ExperimentStage::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isExpired(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        return $this->created_at->addMinutes($this->ttl_minutes)->isPast();
    }
}
