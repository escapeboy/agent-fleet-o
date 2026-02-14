<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use Database\Factories\Domain\Workflow\WorkflowNodeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowNode extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return WorkflowNodeFactory::new();
    }

    protected $fillable = [
        'workflow_id',
        'agent_id',
        'skill_id',
        'crew_id',
        'type',
        'label',
        'position_x',
        'position_y',
        'config',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'type' => WorkflowNodeType::class,
            'position_x' => 'integer',
            'position_y' => 'integer',
            'config' => 'array',
            'order' => 'integer',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class, 'source_node_id')->orderBy('sort_order');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class, 'target_node_id');
    }

    public function isStart(): bool
    {
        return $this->type === WorkflowNodeType::Start;
    }

    public function isEnd(): bool
    {
        return $this->type === WorkflowNodeType::End;
    }

    public function isAgent(): bool
    {
        return $this->type === WorkflowNodeType::Agent;
    }

    public function isConditional(): bool
    {
        return $this->type === WorkflowNodeType::Conditional;
    }

    public function isCrew(): bool
    {
        return $this->type === WorkflowNodeType::Crew;
    }

    public function requiresAgent(): bool
    {
        return $this->type->requiresAgent();
    }

    public function timeout(): int
    {
        return $this->config['timeout'] ?? 300;
    }

    public function maxRetries(): int
    {
        return $this->config['retries'] ?? 2;
    }
}
