<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Integration\Events\IntegrationActionExecuted;
use App\Domain\Integration\Models\Integration;

class LogIntegrationExecution
{
    public function handle(IntegrationActionExecuted $event): void
    {
        $integration = $event->integration;
        $eventName = $event->eventName;
        $ocsf = OcsfMapper::classify($eventName);

        AuditEntry::create([
            'team_id' => $integration->getAttribute('team_id'),
            'user_id' => optional(auth()->user())->id,
            'event' => $eventName,
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'impersonator_id' => session('impersonating_from'),
            'subject_type' => Integration::class,
            'subject_id' => $integration->getKey(),
            'properties' => [
                'driver' => $integration->getAttribute('driver'),
                'integration_name' => $integration->getAttribute('name'),
                'action' => $event->action,
                'params_keys' => array_keys($event->params),
                'success' => $event->success,
                'latency_ms' => $event->latencyMs,
                'error' => $event->errorMessage,
            ],
            'created_at' => now(),
        ]);
    }
}
