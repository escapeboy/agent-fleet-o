<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Shared\Traits\HasPluginMeta;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Models\User;
use Database\Factories\Domain\Workflow\WorkflowFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property WorkflowStatus $status
 * @property int $version
 * @property int $max_loop_iterations
 * @property int|null $estimated_cost_credits
 * @property int|null $budget_cap_credits
 * @property bool $mcp_exposed
 * @property string|null $mcp_tool_name
 * @property string|null $mcp_execution_mode
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $meta
 * @property array<string, mixed>|null $observability_config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, WorkflowNode> $nodes
 * @property-read Collection<int, WorkflowEdge> $edges
 * @property-read Collection<int, Experiment> $experiments
 */
class Workflow extends Model
{
    use BelongsToTeam, HasFactory, HasPluginMeta, HasUuids;

    protected static function newFactory()
    {
        return WorkflowFactory::new();
    }

    protected $hidden = ['observability_config'];

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
        'budget_cap_credits',
        'mcp_exposed',
        'mcp_tool_name',
        'mcp_execution_mode',
        'settings',
        'meta',
        'observability_config',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'observability_config' => 'array',
            'status' => WorkflowStatus::class,
            'version' => 'integer',
            'max_loop_iterations' => 'integer',
            'estimated_cost_credits' => 'integer',
            'budget_cap_credits' => 'integer',
            'mcp_exposed' => 'boolean',
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
