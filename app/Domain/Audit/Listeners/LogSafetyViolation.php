<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Infrastructure\AI\Events\SafetyViolationDetected;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists gateway safety violations so they are queryable (audit log, EU AI Act
 * report) instead of living only in the application log. The matched snippet is
 * not stored: secret and PII scanners match exactly the data that must not be
 * copied into a long-retained, exportable audit trail.
 */
class LogSafetyViolation
{
    public const EVENT = 'ai_safety.violation';

    public function handle(SafetyViolationDetected $event): void
    {
        if ($event->request->teamId === null) {
            return;
        }

        $ocsf = OcsfMapper::classify(self::EVENT);

        // Dispatched synchronously inside the gateway call: an audit write failure
        // must never fail the LLM request.
        try {
            AuditEntry::create([
                'team_id' => $event->request->teamId,
                'user_id' => $event->request->userId,
                'event' => self::EVENT,
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'properties' => [
                    'rule_id' => $event->violation['rule_id'],
                    'severity' => $event->violation['severity'],
                    'target' => $event->violation['target'],
                    'mode' => $event->mode,
                    'strike_count' => $event->strikeCount,
                    'agent_id' => $event->request->agentId,
                    'provider' => $event->request->provider,
                    'model' => $event->request->model,
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('LogSafetyViolation: could not record audit entry', ['exception' => $e::class]);
        }
    }
}
