<?php

namespace App\Domain\Credential\Jobs;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Credential\Services\SecretPatternLibrary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Asynchronous secret scanner.
 *
 * Runs SecretPatternLibrary against the provided text fields and writes
 * AuditEntry records (event = 'secret_detected') for any findings.
 *
 * Redis deduplication prevents duplicate audit entries when the same model
 * is saved multiple times within a 24-hour window without field changes.
 */
class CredentialScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Maximum retry attempts before the job is discarded. */
    public int $tries = 3;

    /**
     * @param  string  $teamId  Team that owns the scanned model.
     * @param  string  $subjectType  Morph-map key (e.g. 'agent', 'skill').
     * @param  string  $subjectId  UUID of the scanned model.
     * @param  array<string, string>  $fields  Field name => text content pairs to scan.
     * @param  string  $contentHash  SHA1 of the concatenated field values for dedup.
     */
    public function __construct(
        private readonly string $teamId,
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly array $fields,
        private readonly string $contentHash,
    ) {
        $this->onQueue('default');
    }

    public function handle(SecretPatternLibrary $library): void
    {
        // Redis deduplication: skip if we already scanned this exact content hash
        // within the last 24 hours to avoid flooding the audit log on bulk saves.
        $dedupKey = "secret_scan:{$this->subjectType}:{$this->subjectId}:{$this->contentHash}";

        if (Cache::has($dedupKey)) {
            return;
        }

        // Mark as scanned for 24 hours.
        Cache::put($dedupKey, '1', 86400);

        $findings = $library->scanFields($this->fields);

        foreach ($findings as $finding) {
            AuditEntry::withoutGlobalScopes()->create([
                'team_id' => $this->teamId,
                'user_id' => null,
                'event' => 'secret_detected',
                'ocsf_class_uid' => 6003, // OCSF: Security Finding
                'ocsf_severity_id' => 3,  // Medium
                'subject_type' => $this->subjectType,
                'subject_id' => $this->subjectId,
                'properties' => [
                    'pattern_id' => $finding['pattern_id'],
                    'pattern_name' => $finding['name'],
                    'field' => $finding['field'],
                    'content_hash' => $this->contentHash,
                ],
                'triggered_by' => 'secret_scanner',
                'created_at' => now(),
            ]);
        }
    }
}
