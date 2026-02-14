<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Models\User;
use Database\Factories\Domain\Workflow\WorkflowFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected static function newFactory()
    {
        return WorkflowFactory::new();
    }

    protected $fillable = [
        'team_id',
        'user_id',
        'name',
        'slug',
        'description',
        'status',
        'version',
        'max_loop_iterations',
        'estimated_cost_credits',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'version' => 'integer',
            'max_loop_iterations' => 'integer',
            'estimated_cost_credits' => 'integer',
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class)->orderBy('order');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class);
    }

    public function experiments(): HasMany
    {
        return $this->hasMany(Experiment::class);
    }

    public function startNode(): ?WorkflowNode
    {
        return $this->nodes()->where('type', 'start')->first();
    }

    public function endNodes()
    {
        return $this->nodes()->where('type', 'end');
    }

    public function agentNodes()
    {
        return $this->nodes()->where('type', 'agent');
    }

    public function isDraft(): bool
    {
        return $this->status === WorkflowStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === WorkflowStatus::Active;
    }

    public function nodeCount(): int
    {
        return $this->nodes()->count();
    }

    public function agentNodeCount(): int
    {
        return $this->agentNodes()->count();
    }
}
