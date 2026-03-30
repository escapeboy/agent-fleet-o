<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\VoiceSession\Actions\CreateVoiceSessionAction;
use App\Domain\VoiceSession\Actions\EndVoiceSessionAction;
use App\Domain\VoiceSession\Enums\VoiceSessionStatus;
use App\Domain\VoiceSession\Exceptions\VoiceSessionException;
use App\Domain\VoiceSession\Models\VoiceSession;
use App\Domain\VoiceSession\Services\LiveKitCredentialResolver;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Livewire page for managing a real-time voice session with an AI agent.
 *
 * This component handles server-side session state. The actual WebRTC connection
 * is managed client-side by Alpine.js using the @livekit/client JS SDK.
 * Token retrieval and WebRTC setup happen in the browser; this component
 * provides the session record and LiveKit URL/room info.
 */
class VoiceSessionPage extends Component
{
    public Agent $agent;

    public ?VoiceSession $session = null;

    public string $statusMessage = '';

    public string $error = '';

    /** Whether the team has LiveKit credentials configured (integration or env). */
    public bool $needsIntegration = false;

    /** LiveKit server URL resolved for this team. */
    public string $livekitUrl = '';

    /** @var array<int, array{role: string, content: string, timestamp: string}> */
    public array $transcript = [];

    public function mount(Agent $agent, LiveKitCredentialResolver $resolver): void
    {
        $this->agent = $agent;

        /** @var Team $team */
        $team = auth()->user()->currentTeam;

        $this->needsIntegration = ! $resolver->hasCredentials($team);

        if (! $this->needsIntegration) {
            $credentials = $resolver->resolve($team);
            $this->livekitUrl = $credentials['url'];
        }

        // Resume any open session for this agent
        $this->session = VoiceSession::withoutGlobalScopes()
            ->where('team_id', auth()->user()->current_team_id)
            ->where('agent_id', $agent->id)
            ->whereIn('status', [VoiceSessionStatus::Pending->value, VoiceSessionStatus::Active->value])
            ->latest()
            ->first();

        if ($this->session) {
            $this->transcript = $this->session->transcript ?? [];
            $this->statusMessage = 'Reconnecting to active session...';
        }
    }

    /**
     * Create a new voice session and return connection info to the browser.
     * The browser's Alpine.js component will use the returned token to connect to LiveKit.
     *
     * @return array{token: string, livekit_url: string, room_name: string}|array{}
     */
    public function startSession(CreateVoiceSessionAction $action): array
    {
        $this->error = '';

        if ($this->needsIntegration) {
            $this->error = 'Please connect a LiveKit integration before starting a voice session.';

            return [];
        }

        try {
            $result = $action->execute(
                teamId: auth()->user()->current_team_id,
                agentId: $this->agent->id,
                createdBy: auth()->user()->id,
            );
        } catch (VoiceSessionException $e) {
            $this->error = $e->getMessage();

            return [];
        }

        $this->session = $result['session'];
        $this->livekitUrl = $result['livekit_url'];
        $this->statusMessage = 'Connecting to room...';

        return [
            'token' => $result['token'],
            'livekit_url' => $result['livekit_url'],
            'room_name' => $this->session->room_name,
        ];
    }

    /** End the current session. Called from the browser when the user hangs up. */
    public function endSession(EndVoiceSessionAction $action): void
    {
        if (! $this->session) {
            return;
        }

        try {
            $action->execute($this->session);
        } catch (VoiceSessionException) {
            // Already ended — safe to ignore
        }

        $this->session = null;
        $this->transcript = [];
        $this->statusMessage = 'Session ended.';
    }

    /** Poll transcript from the database (called by Alpine.js on a short interval). */
    public function refreshTranscript(): void
    {
        if (! $this->session) {
            return;
        }

        $this->session->refresh();
        $this->transcript = $this->session->transcript ?? [];
    }

    public function render(): View
    {
        return view('livewire.agents.voice-session-page')
            ->title("Voice Session — {$this->agent->name}");
    }
}
