<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Illuminate\Support\Facades\Log;

class DisableAgentAction
{
    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
    ) {}

    public function execute(Agent $agent, string $reason = 'Manual disable'): void
    {
        $agent->update([
            'status' => AgentStatus::Disabled,
        ]);

        $this->circuitBreaker->reset($agent->provider);

        $ocsf = OcsfMapper::classify('agent.disabled');
        AuditEntry::create([
            'team_id' => $agent->team_id,
            'event' => 'agent.disabled',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => Agent::class,
            'subject_id' => $agent->id,
            'properties' => [
                'agent_name' => $agent->name,
                'provider' => $agent->provider,
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        Log::info("Agent disabled: {$agent->name}", [
            'agent_id' => $agent->id,
            'provider' => $agent->provider,
            'reason' => $reason,
        ]);
    }
}
