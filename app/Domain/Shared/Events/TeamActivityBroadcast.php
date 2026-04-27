<?php

namespace App\Domain\Shared\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight unified event broadcast on the per-team activity channel,
 * powering the /team-graph live firehose.
 *
 * Listeners on AgentExecuted, ExperimentTransitioned, etc. translate
 * domain events into this normalized shape so the frontend listens to
 * a single channel + event name.
 */
class TeamActivityBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $teamId,
        public readonly string $kind,
        public readonly ?string $actorId,
        public readonly string $actorKind,
        public readonly string $actorLabel,
        public readonly string $summary,
        public readonly string $at,
        public readonly array $extra = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->teamId}.activity")];
    }

    public function broadcastAs(): string
    {
        return 'team-activity';
    }

    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind,
            'actor_id' => $this->actorId,
            'actor_kind' => $this->actorKind,
            'actor_label' => $this->actorLabel,
            'summary' => $this->summary,
            'at' => $this->at,
            'extra' => $this->extra,
        ];
    }
}
