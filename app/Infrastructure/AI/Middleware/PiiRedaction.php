<?php

namespace App\Infrastructure\AI\Middleware;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Guardrails\Scanners\PiiScanner;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Per-team opt-in masking of structured PII (emails, IBANs, Luhn-valid card
 * numbers) in the system and user prompts before a cloud provider call.
 * Local CLI agents, self-hosted HTTP providers and Bridge providers are
 * skipped: the prompt does not leave the operator's infrastructure there.
 *
 * Only counts per type are audited, never the values. Fail-open like
 * SafetyClassifier: if masking fails, the original request goes through.
 */
class PiiRedaction implements AiMiddlewareInterface
{
    private readonly PiiScanner $scanner;

    public function __construct(?PiiScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new PiiScanner;
    }

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if ($request->teamId === null || $this->isNonCloudProvider($request->provider)) {
            return $next($request);
        }

        try {
            $result = $this->scanner->redactAll([$request->systemPrompt, $request->userPrompt]);

            // Scan first so prompts without PII never cost a team lookup.
            if (array_sum($result['counts']) === 0 || ! $this->isEnabledFor($request->teamId)) {
                return $next($request);
            }

            $masked = $request->withPrompts($result['texts'][0], $result['texts'][1]);
        } catch (\Throwable $e) {
            Log::warning('PiiRedaction: masking failed, passing request through', ['error' => $e->getMessage()]);

            return $next($request);
        }

        $this->audit($request, $result['counts']);

        return $next($masked);
    }

    private function isNonCloudProvider(string $provider): bool
    {
        if ($provider === 'local') {
            return true;
        }

        return (bool) config("llm_providers.{$provider}.local", false)
            || (bool) config("llm_providers.{$provider}.bridge", false)
            || (bool) config("llm_providers.{$provider}.http_local", false);
    }

    private function isEnabledFor(string $teamId): bool
    {
        $team = Team::withoutGlobalScopes()->find($teamId);

        return (bool) ($team?->settings['pii_redaction_enabled'] ?? config('ai_safety.pii_redaction.default', false));
    }

    /**
     * @param  array{email: int, iban: int, card: int}  $counts
     */
    private function audit(AiRequestDTO $request, array $counts): void
    {
        try {
            $ocsf = OcsfMapper::classify('ai.pii_redacted');

            AuditEntry::create([
                'team_id' => $request->teamId,
                'user_id' => $request->userId,
                'event' => 'ai.pii_redacted',
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'properties' => [
                    'provider' => $request->provider,
                    'model' => $request->model,
                    'purpose' => $request->purpose,
                    'agent_id' => $request->agentId,
                    'experiment_id' => $request->experimentId,
                    'counts' => $counts,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
