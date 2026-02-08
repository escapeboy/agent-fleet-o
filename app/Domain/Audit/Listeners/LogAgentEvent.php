<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditEntry;

class LogAgentEvent
{
    public function handle(object $event): void
    {
        if (!isset($event->agent) || !$event->agent instanceof Agent) {
            return;
        }

        $agent = $event->agent;
        $eventName = $event->eventName ?? 'agent.updated';

        AuditEntry::create([
            'event' => $eventName,
            'subject_type' => Agent::class,
            'subject_id' => $agent->id,
            'properties' => [
                'agent_name' => $agent->name,
                'provider' => $agent->provider,
                'status' => $agent->status->value,
                'reason' => $event->reason ?? null,
            ],
            'created_at' => now(),
        ]);
    }
}
