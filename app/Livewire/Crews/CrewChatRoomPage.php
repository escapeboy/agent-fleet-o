<?php

namespace App\Livewire\Crews;

use App\Domain\Crew\Models\CrewAgentMessage;
use App\Domain\Crew\Models\CrewChatMessage;
use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Read-only view of inter-agent communication for a single crew execution:
 * the directed/broadcast CrewAgentMessage timeline, the chat-room CrewChatMessage
 * transcript, and the ephemeral Redis blackboard posts.
 *
 * Uses `public string $executionId` + a computed resolver (NOT route-model
 * binding) to avoid the Postgres 22P02 invalid-uuid trap on a bad route param,
 * and relies on the global TeamScope for tenant isolation: a cross-team id
 * resolves to null and the page 404s.
 */
class CrewChatRoomPage extends Component
{
    public string $executionId;

    public function mount(string $execution): void
    {
        $this->executionId = $execution;

        abort_unless($this->execution !== null, 404);
    }

    #[Computed]
    public function execution(): ?CrewExecution
    {
        // TeamScope is applied via the global scope on CrewExecution, so an id
        // belonging to another team resolves to null here.
        return CrewExecution::query()
            ->with('crew')
            ->find($this->executionId);
    }

    /**
     * Inter-agent messages (directed + broadcast). CrewAgentMessage carries its
     * own team_id, so scope it explicitly and unconditionally — never via when().
     *
     * @return Collection<int, CrewAgentMessage>
     */
    #[Computed]
    public function agentMessages(): Collection
    {
        $execution = $this->execution;

        if ($execution === null) {
            return collect();
        }

        return CrewAgentMessage::query()
            ->where('team_id', $execution->team_id)
            ->where('crew_execution_id', $execution->id)
            ->with(['sender', 'recipient'])
            ->orderBy('round')
            ->orderBy('created_at')
            ->limit(500)
            ->get();
    }

    /**
     * Chat-room transcript. CrewChatMessage has no team_id of its own; it is
     * scoped transitively through the already-team-scoped CrewExecution relation.
     *
     * @return Collection<int, CrewChatMessage>
     */
    #[Computed]
    public function chatMessages(): Collection
    {
        $execution = $this->execution;

        if ($execution === null) {
            return collect();
        }

        return $execution->chatMessages()->with('agent')->limit(500)->get();
    }

    /**
     * Blackboard posts live in a Redis sorted set with a 24h TTL — ephemeral,
     * not a queryable DB model. After expiry this list is simply empty. Access
     * is gated by the team-scoped execution resolved above.
     *
     * @return array<int, array{type: string, agent_name: ?string, message: string, ts: ?string}>
     */
    #[Computed]
    public function blackboardPosts(): array
    {
        $execution = $this->execution;

        if ($execution === null) {
            return [];
        }

        $raw = Redis::zrange('crew:blackboard:'.$execution->id, 0, -1);

        return collect($raw)
            ->map(fn ($entry) => json_decode((string) $entry, true))
            ->filter(fn ($decoded) => is_array($decoded))
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.crews.crew-chat-room-page')
            ->layout('layouts.app', ['header' => 'Crew Chat Room']);
    }
}
