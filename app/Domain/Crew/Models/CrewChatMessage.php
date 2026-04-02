<?php

namespace App\Domain\Crew\Models;

use App\Domain\Agent\Models\Agent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message in a crew chat room execution.
 *
 * Chat room mode uses a shared message bus where all agents see all messages.
 * Messages are organized into rounds; each round lets every active participant
 * contribute before the orchestrator evaluates convergence.
 *
 * @property string $crew_execution_id
 * @property string|null $agent_id
 * @property string|null $agent_name
 * @property string $role
 * @property string $content
 * @property int $round
 * @property array $metadata
 */
class CrewChatMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'crew_execution_id',
        'agent_id',
        'agent_name',
        'role',
        'content',
        'round',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class, 'crew_execution_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
