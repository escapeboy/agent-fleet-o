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
 * Team switch: teams.settings['pii_redaction_enabled'] (default from
 * config('ai_safety.pii_redaction.default')).
 *
 * The audit entry only records counts per type, never the values. Fails
 * open: if masking throws, the original request is passed through.
 */
class PiiRedaction implements AiMiddlewareInterface
{
    public function __construct(private readonly PiiScanner $scanner = new PiiScanner) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if (! $this->appliesTo($request)) {
            return $next($request);
        }

        try {
            $result = $this->scanner->redactAll([$request->systemPrompt, $request->userPrompt]);
        } catch (\Throwable $e) {
            Log::warning('PiiRedaction: masking failed, passing request through', ['error' => $e->getMessage()]);

            return $next($request);
        }

        if (array_sum($result['counts']) === 0) {
            return $next($request);
        }

        $this->audit($request, $result['counts']);

        return $next($request->withPrompts($result['texts'][0], $result['texts'][1]));
    }

    private function appliesTo(AiRequestDTO $request): bool
    {
        if ($request->teamId === null || $request->provider === 'local') {
            return false;
        }

        $provider = $request->provider;

        if (config("llm_providers.{$provider}.local")
            || config("llm_providers.{$provider}.bridge")
            || config("llm_providers.{$provider}.http_local")) {
            return false;
        }

        try {
            $team = Team::withoutGlobalScopes()->find($request->teamId);
        } catch (\Throwable $e) {
            Log::warning('PiiRedaction: team lookup failed', ['error' => $e->getMessage()]);

            return false;
        }

        $settings = $team !== null ? ($team->settings ?? []) : [];

        return (bool) ($settings['pii_redaction_enabled'] ?? config('ai_safety.pii_redaction.default', false));
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
