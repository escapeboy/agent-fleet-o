<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a workflow's graph (nodes + edges) as it was at a
 * given version, written just before an active workflow's graph is replaced.
 * The graph uses the same shape UpdateWorkflowAction::replaceGraph() accepts,
 * so it can be fed straight back in to restore.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string $workflow_id
 * @property int $version
 * @property string|null $created_by
 * @property array{nodes?: array<int, array<string, mixed>>, edges?: array<int, array<string, mixed>>} $graph
 * @property string|null $restored_from_version_id
 * @property CarbonInterface|null $created_at
 * @property-read User|null $createdBy
 */
class WorkflowVersion extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'workflow_id',
        'version',
        'created_by',
        'graph',
        'restored_from_version_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'graph' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function nodeCount(): int
    {
        return count($this->graph['nodes'] ?? []);
    }

    public function edgeCount(): int
    {
        return count($this->graph['edges'] ?? []);
    }
}
