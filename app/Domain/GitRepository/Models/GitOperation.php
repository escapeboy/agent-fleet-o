<?php

namespace App\Domain\GitRepository\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Enums\GitOperationStatus;
use App\Domain\GitRepository\Enums\GitOperationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitOperation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'git_repository_id',
        'agent_id',
        'experiment_id',
        'operation_type',
        'status',
        'payload',
        'result',
        'duration_ms',
        'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'operation_type' => GitOperationType::class,
            'status' => GitOperationStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (GitOperation $op) {
            $op->created_at = now();
        });
    }

    public function gitRepository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === GitOperationStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === GitOperationStatus::Failed;
    }
}
