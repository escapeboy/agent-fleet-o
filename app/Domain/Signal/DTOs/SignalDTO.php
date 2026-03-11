<?php

namespace App\Domain\Signal\DTOs;

/**
 * Normalized signal data produced by an integration driver after processing
 * an inbound webhook payload or a polled event.
 *
 * Passed from IntegrationSignalBridge to IngestSignalAction.
 */
readonly class SignalDTO
{
    public function __construct(
        /** Stable identifier within the source (e.g. "owner/repo#42"). */
        public string $sourceIdentifier,
        /** Provider-assigned deduplication ID (e.g. "issues.opened.node_abc"). */
        public ?string $sourceNativeId,
        /** Structured payload to store on the signal. */
        public array $payload,
        /** Classification tags (e.g. ['github', 'issues', 'opened']). */
        public array $tags = [],
    ) {}
}
