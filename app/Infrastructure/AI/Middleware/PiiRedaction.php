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
use Throwable;

/**
 * Per-team opt-in masking of structured PII (emails, IBANs, Luhn-valid card
 * numbers) in the system and user prompts before a cloud provider call.
 * Values are replaced with numbered placeholders ([EMAIL_1], [IBAN_1],
 * [CARD_1]). Local, self-hosted (http_local) and Bridge providers are skipped.
 *
 * Only per-type counts are audited, never the values. Responses are not
 * un-masked, and tool results inside the Prism tool loop are not masked.
 *
 * Fail-open: if masking throws, the original request is passed through.
 */
class PiiRedaction implements AiMiddlewareInterface
{
    public const AUDIT_EVENT = 'ai.pii_redacted';

    private readonly PiiScanner $scanner;

    public function __construct(?PiiScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new PiiScanner;
    }

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        if (! $this->appliesTo($request)) {
            return $next($request);
        }

        try {
            $result = $this->scanner->redactAll([$request->systemPrompt, $request->userPrompt]);
        } catch (Throwable $e) {
            Log::warning('PiiRedaction: masking failed, passing request through', ['exception' => $e::class]);

            return $next($request);
        }

        $counts = $result['counts'];

        if (array_sum($counts) === 0) {
            return $next($request);
        }

        $this->audit($request, $counts);

        return $next($request->withPrompts($result['texts'][0] ?? '', $result['texts'][1] ?? ''));
    }

    private function appliesTo(AiRequestDTO $request): bool
    {
        if ($request->teamId === null || $request->provider === 'local') {
            return false;
        }

        $providerConfig = "llm_providers.{$request->provider}";

        if (config("{$providerConfig}.local") || config("{$providerConfig}.bridge") || config("{$providerConfig}.http_local")) {
            return false;
        }

        try {
            $team = Team::withoutGlobalScopes()->find($request->teamId);
        } catch (Throwable $e) {
            Log::warning('PiiRedaction: team lookup failed', ['exception' => $e::class]);

            return false;
        }

        $settings = $team === null ? [] : ($team->settings ?? []);

        return (bool) ($settings['pii_redaction_enabled'] ?? config('ai_safety.pii_redaction.default', false));
    }

    /**
     * @param  array{email: int, iban: int, card: int}  $counts
     */
    private function audit(AiRequestDTO $request, array $counts): void
    {
        try {
            $ocsf = OcsfMapper::classify(self::AUDIT_EVENT);

            AuditEntry::create([
                'team_id' => $request->teamId,
                'user_id' => $request->userId,
                'event' => self::AUDIT_EVENT,
                'ocsf_class_uid' => $ocsf['class_uid'],
                'ocsf_severity_id' => $ocsf['severity_id'],
                'properties' => [
                    'provider' => $request->provider,
                    'model' => $request->model,
                    'purpose' => $request->purpose,
                    'agent_id' => $request->agentId,
                    'experiment_id' => $request->experimentId,
                    // Counts only: the masked values must never reach the audit trail.
                    'counts' => $counts,
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
