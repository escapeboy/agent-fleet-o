<?php

namespace FleetQ\BorunaAudit\Services;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use FleetQ\BorunaAudit\DTOs\VerificationResult;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Enums\VerificationStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use FleetQ\BorunaAudit\Models\BundleVerification;

class BundleVerifier
{
    public function __construct(
        private readonly McpStdioClient $client,
        private readonly BundleStorage $storage,
    ) {}

    public function verify(AuditableDecision $decision, string $tenantId): VerificationResult
    {
        $start = hrtime(true);

        if ($decision->bundle_path === null) {
            $result = VerificationResult::fail('No bundle path recorded for this decision.');
            $this->recordVerification($decision, $tenantId, $result, 0);

            return $result;
        }

        $evidenceData = $this->storage->readEvidenceFile($decision->bundle_path);

        if ($evidenceData === null) {
            $result = VerificationResult::fail('Bundle evidence file not found on disk.', $decision->bundle_path);
            $this->recordVerification($decision, $tenantId, $result, 0);
            $decision->update(['status' => DecisionStatus::Tampered]);

            return $result;
        }

        $tool = $this->resolveTool($tenantId);

        if ($tool !== null) {
            try {
                $raw = $this->client->callTool($tool, 'boruna_evidence', [
                    'run_id' => $decision->run_id,
                    'bundle_path' => $this->storage->bundleAbsolutePath($decision->bundle_path),
                ]);

                $response = json_decode($raw, true);
                $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

                $passed = ($response['verified'] ?? false) === true;

                if ($passed) {
                    $result = VerificationResult::pass($decision->bundle_path);
                } else {
                    $result = VerificationResult::fail($response['error'] ?? 'Verification failed.', $decision->bundle_path);
                    $decision->update(['status' => DecisionStatus::Tampered]);
                }

                $this->recordVerification($decision, $tenantId, $result, $latencyMs);

                return $result;
            } catch (\Throwable $e) {
                // Fall through to offline hash-chain check
            }
        }

        // Offline: validate hash chain integrity from stored evidence
        $result = $this->verifyHashChainOffline($evidenceData, $decision->bundle_path);
        $latencyMs = (int) ((hrtime(true) - $start) / 1_000_000);

        if (! $result->passed) {
            $decision->update(['status' => DecisionStatus::Tampered]);
        }

        $this->recordVerification($decision, $tenantId, $result, $latencyMs);

        return $result;
    }

    private function verifyHashChainOffline(array $evidenceData, string $bundlePath): VerificationResult
    {
        $chain = $evidenceData['hash_chain'] ?? null;

        if (! is_array($chain) || empty($chain)) {
            return VerificationResult::fail('No hash chain found in evidence bundle.', $bundlePath);
        }

        // Validate chain linkage: each entry's prev_hash must match sha256 of prior entry
        $prev = null;
        foreach ($chain as $entry) {
            if ($prev !== null) {
                $expectedPrev = hash('sha256', json_encode($prev));
                if (($entry['prev_hash'] ?? null) !== $expectedPrev) {
                    return VerificationResult::fail('Hash chain broken — evidence may be tampered.', $bundlePath);
                }
            }
            $prev = $entry;
        }

        return VerificationResult::pass($bundlePath);
    }

    private function recordVerification(
        AuditableDecision $decision,
        string $tenantId,
        VerificationResult $result,
        int $latencyMs,
    ): void {
        BundleVerification::create([
            'team_id' => $tenantId,
            'auditable_decision_id' => $decision->id,
            'status' => $result->passed ? VerificationStatus::Passed : VerificationStatus::Failed,
            'checked_at' => $result->checkedAt,
            'error_message' => $result->errorMessage,
            'latency_ms' => $latencyMs,
        ]);
    }

    private function resolveTool(string $tenantId): ?Tool
    {
        return Tool::where('team_id', $tenantId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where('subkind', 'boruna')
            ->first();
    }
}
