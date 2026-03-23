<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;

class LogAgentEvent
{
    public function handle(object $event): void
    {
        if (! isset($event->agent) || ! $event->agent instanceof Agent) {
            return;
        }

        $agent = $event->agent;
        $eventName = $event->eventName ?? 'agent.updated';
        $ocsf = OcsfMapper::classify($eventName);

        AuditEntry::create([
            'event' => $eventName,
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'impersonator_id' => session('impersonating_from'),
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
