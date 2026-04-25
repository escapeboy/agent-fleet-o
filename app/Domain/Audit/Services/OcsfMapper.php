<?php

namespace App\Domain\Audit\Services;

/**
 * Maps internal audit event strings to OCSF (Open Cybersecurity Schema Framework) class
 * and severity identifiers for structured security event categorisation.
 *
 * Class UIDs follow the OCSF v1 taxonomy:
 *   1001 — Process Activity
 *   2001 — Authentication
 *   3001 — Account Change
 *   3002 — API Activity
 *   3006 — Financial Activity
 *   4002 — HTTP Activity
 *
 * Severity IDs:
 *   1 — Informational
 *   2 — Low
 *   3 — Medium
 *   4 — High
 */
class OcsfMapper
{
    /**
     * Classify an event string into OCSF class_uid and severity_id.
     *
     * @return array{class_uid: int, severity_id: int}
     */
    public static function classify(string $event): array
    {
        // High-severity overrides: failed or errored events regardless of prefix
        if (str_ends_with($event, '.failed') || str_ends_with($event, '.error')) {
            $base = self::baseClassUid($event);

            return ['class_uid' => $base, 'severity_id' => 4];
        }

        // Specific high-severity event
        if ($event === 'agent.classification_blocked') {
            return ['class_uid' => 2001, 'severity_id' => 4];
        }

        return [
            'class_uid' => self::baseClassUid($event),
            'severity_id' => self::baseSeverityId($event),
        ];
    }

    /**
     * Resolve base OCSF class_uid from the event prefix.
     */
    private static function baseClassUid(string $event): int
    {
        return match (true) {
            str_starts_with($event, 'experiment.') => 3002,  // API Activity
            str_starts_with($event, 'agent.') => 3002,       // API Activity
            str_starts_with($event, 'integration.') => 3002, // API Activity
            str_starts_with($event, 'approval.') => 3001,    // Account Change
            str_starts_with($event, 'budget.') => 3006,      // Financial Activity
            str_starts_with($event, 'credential.') => 3001,  // Account Change
            str_starts_with($event, 'bash.') => 1001,        // Process Activity
            str_starts_with($event, 'browser.') => 4002,     // HTTP Activity
            str_starts_with($event, 'human_task.') => 3001,  // Account Change (workflow state)
            str_starts_with($event, 'user.') => 2001,        // Authentication
            str_starts_with($event, 'clarification.') => 3002,
            str_starts_with($event, 'feedback.') => 3002,
            $event === 'signal_webhook_secret_rotated' => 3001, // Account Change (secret rotation)
            default => 3002,
        };
    }

    /**
     * Resolve base OCSF severity_id from the event prefix.
     */
    private static function baseSeverityId(string $event): int
    {
        return match (true) {
            str_starts_with($event, 'experiment.') => 1,
            str_starts_with($event, 'agent.') => 1,
            str_starts_with($event, 'integration.') => 1,
            str_starts_with($event, 'approval.') => 2,
            str_starts_with($event, 'budget.') => 3,
            str_starts_with($event, 'credential.') => 3,
            str_starts_with($event, 'bash.') => 2,
            str_starts_with($event, 'browser.') => 1,
            str_starts_with($event, 'human_task.') => 2,
            str_starts_with($event, 'user.') => 3,            // user events are medium by default
            $event === 'user.tokens_revoked' => 4,            // token revocation is high
            $event === 'signal_webhook_secret_rotated' => 3,  // secret rotation is medium
            str_starts_with($event, 'clarification.') => 1,
            str_starts_with($event, 'feedback.') => 1,
            default => 1,
        };
    }
}
