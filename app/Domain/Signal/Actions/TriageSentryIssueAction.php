<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\DTOs\SentryTriageResult;
use App\Domain\Signal\Enums\FixTier;
use App\Domain\Signal\Enums\SentryTriageOutcome;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SentryFixTierClassifier;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Triages a single Sentry-sourced Signal: investigates the root cause with an
 * LLM, classifies the fix risk tier, and — in phase 1 — delegates an autonomous
 * fix for actionable parent-repo issues. Critical issues are alerted immediately.
 *
 * Read-only in phase 0 (investigate + return for the digest). Never throws —
 * a failed investigation degrades to investigate-only.
 */
class TriageSentryIssueAction
{
    /** Statuses that mean the signal is already handled — skip re-triage. */
    private const TERMINAL_STATUSES = [
        SignalStatus::DelegatedToAgent,
        SignalStatus::AgentFixing,
        SignalStatus::Resolved,
        SignalStatus::Dismissed,
    ];

    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly AiGatewayInterface $gateway,
        private readonly SentryFixTierClassifier $classifier,
        private readonly DelegateBugReportToAgentAction $delegate,
        private readonly NotifyCriticalSentryIssueAction $notifyCritical,
    ) {}

    public function execute(Signal $signal): SentryTriageResult
    {
        // Idempotency — never re-triage a signal already delegated or closed.
        if ($signal->experiment_id !== null
            || in_array($signal->status, self::TERMINAL_STATUSES, true)) {
            return SentryTriageResult::skipped($signal->id);
        }

        $team = Team::find($signal->team_id);
        if ($team === null) {
            return SentryTriageResult::failed($signal->id, 'Signal has no resolvable team.');
        }

        $payload = $signal->payload ?? [];
        $investigation = $this->investigate($payload, $team);
        $tier = $this->classifier->classify(
            $investigation['suspect_files'],
            $investigation['estimated_diff_lines'],
        );

        if ($investigation['is_critical']) {
            $this->notifyCritical->execute($signal);
        }

        // Phase 0 — read-only: investigate and report, never delegate.
        if (config('sentry_watchdog.mode') !== 'phase1') {
            return $this->investigateOnlyResult($signal, $tier, $investigation);
        }

        // Phase 1 — delegate an autonomous fix only for actionable, parent-repo,
        // confident issues. base/ issues need the unbuilt submodule-aware merge.
        $threshold = (float) config('sentry_watchdog.confidence_threshold', 0.7);
        $actionable = $investigation['suspect_files'] !== []
            && ! $this->touchesBaseSubmodule($investigation['suspect_files'])
            && $tier !== FixTier::T4
            && $investigation['confidence'] >= $threshold;

        if (! $actionable) {
            return $this->investigateOnlyResult($signal, $tier, $investigation);
        }

        $actorId = Team::ownerIdFor($team->id);
        $actor = $actorId !== null ? User::find($actorId) : null;
        if ($actor === null) {
            return SentryTriageResult::failed($signal->id, 'No team owner to act as delegation actor.');
        }

        // Enrich the signal so DelegateBugReportToAgentAction sees bug-report-shaped
        // context. It expects suspect_files as a list of {path, confidence, reason}.
        // The enrich-save + delegate are wrapped together: a mid-delegation throw
        // must not leave a half-enriched signal that the next run re-delegates.
        try {
            $signal->payload = array_merge($payload, [
                'title' => $payload['title'] ?? 'Sentry issue',
                'suspect_files' => array_map(
                    fn (string $path): array => [
                        'path' => $path,
                        'confidence' => $investigation['confidence'],
                        'reason' => $investigation['root_cause'],
                    ],
                    $investigation['suspect_files'],
                ),
                'sentry_issue_id' => $payload['id'] ?? null,
                'sentry_permalink' => $payload['permalink'] ?? null,
            ]);
            $signal->save();

            $experiment = $this->delegate->execute(
                signal: $signal,
                actor: $actor,
                agentId: null,
                additionalContext: $investigation['root_cause'],
            );
        } catch (\Throwable $e) {
            Log::warning('Sentry watchdog delegation failed', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);

            return SentryTriageResult::failed($signal->id, 'Delegation failed: '.$e->getMessage());
        }

        return new SentryTriageResult(
            signalId: $signal->id,
            outcome: SentryTriageOutcome::Delegated,
            tier: $tier,
            rootCause: $investigation['root_cause'],
            confidence: $investigation['confidence'],
            isCritical: $investigation['is_critical'],
            summary: $investigation['summary'],
            experimentId: $experiment->id,
            suspectFiles: $investigation['suspect_files'],
        );
    }

    /**
     * @param  array{root_cause: string, confidence: float, suspect_files: list<string>, estimated_diff_lines: int, is_critical: bool, summary: string}  $investigation
     */
    private function investigateOnlyResult(Signal $signal, FixTier $tier, array $investigation): SentryTriageResult
    {
        // Stamp the signal so the next watchdog run does not re-investigate it
        // (and re-spend an LLM call). Delegated signals are excluded by their
        // experiment_id instead; this covers the investigate-only outcome.
        $this->markTriaged($signal);

        return new SentryTriageResult(
            signalId: $signal->id,
            outcome: SentryTriageOutcome::InvestigateOnly,
            tier: $tier,
            rootCause: $investigation['root_cause'],
            confidence: $investigation['confidence'],
            isCritical: $investigation['is_critical'],
            summary: $investigation['summary'],
            suspectFiles: $investigation['suspect_files'],
        );
    }

    private function markTriaged(Signal $signal): void
    {
        $payload = $signal->payload ?? [];
        $payload['sentry_watchdog_triaged_at'] = now()->toIso8601String();
        $signal->payload = $payload;
        $signal->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{root_cause: string, confidence: float, suspect_files: list<string>, estimated_diff_lines: int, is_critical: bool, summary: string}
     */
    private function investigate(array $payload, Team $team): array
    {
        $default = [
            'root_cause' => 'Investigation unavailable — see Sentry issue directly.',
            'confidence' => 0.0,
            'suspect_files' => [],
            'estimated_diff_lines' => 0,
            'is_critical' => $this->looksCritical($payload),
            'summary' => $this->clean((string) ($payload['title'] ?? 'Sentry issue'), 200),
        ];

        try {
            $resolved = $this->providerResolver->resolve(team: $team, purpose: 'sentry-triage');

            $request = new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: $this->systemPrompt(),
                userPrompt: $this->userPrompt($payload),
                maxTokens: 1024,
                userId: Team::ownerIdFor($team->id),
                teamId: $team->id,
                purpose: 'sentry-triage',
                temperature: 0.2,
            );

            $response = $this->gateway->complete($request);
            $parsed = $this->extractJson($response->content);
        } catch (\Throwable $e) {
            Log::warning('Sentry watchdog investigation failed', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }

        if ($parsed === null) {
            return $default;
        }

        return [
            'root_cause' => $this->clean((string) ($parsed['root_cause'] ?? $default['root_cause']), 2000),
            'confidence' => $this->clampConfidence($parsed['confidence'] ?? 0.0),
            'suspect_files' => $this->normalizeFiles($parsed['suspect_files'] ?? []),
            'estimated_diff_lines' => max(0, (int) ($parsed['estimated_diff_lines'] ?? 0)),
            'is_critical' => (bool) ($parsed['is_critical'] ?? $default['is_critical']),
            'summary' => $this->clean((string) ($parsed['summary'] ?? $default['summary']), 400),
        ];
    }

    private function systemPrompt(): string
    {
        return 'You are a senior engineer triaging a production error from Sentry. '
            .'Identify the most likely root cause and the repository files that would need to change. '
            .'Respond with ONLY a single JSON object, no prose, with keys: '
            .'root_cause (string), confidence (number 0-1), suspect_files (array of repo-relative paths), '
            .'estimated_diff_lines (integer), is_critical (boolean — true for data loss, outages, or auth/billing breakage), '
            .'summary (string, one sentence).';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function userPrompt(array $payload): string
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $lines = [
            'Title: '.$this->clean((string) ($payload['title'] ?? 'unknown'), 300),
            'Culprit: '.$this->clean((string) ($payload['culprit'] ?? 'unknown'), 300),
            'Level: '.$this->clean((string) ($payload['level'] ?? 'error'), 40),
            'Event count: '.$this->clean((string) ($payload['count'] ?? '?'), 40),
            'Exception type: '.$this->clean((string) ($metadata['type'] ?? 'unknown'), 200),
            'Exception value: '.$this->clean((string) ($metadata['value'] ?? 'unknown'), 600),
        ];

        return implode("\n", $lines);
    }

    /**
     * Extract the first balanced JSON object from raw LLM text. Local agents
     * wrap output in markdown fences and may emit trailing text, so a plain
     * json_decode of the whole response is unreliable.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $raw): ?array
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode(substr($raw, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function looksCritical(array $payload): bool
    {
        $level = strtolower((string) ($payload['level'] ?? ''));
        $count = (int) ($payload['count'] ?? 0);

        return $level === 'fatal' || $count > 100;
    }

    /**
     * @param  mixed  $files
     * @return list<string>
     */
    private function normalizeFiles($files): array
    {
        if (! is_array($files)) {
            return [];
        }

        $normalized = [];
        foreach ($files as $file) {
            if (! is_string($file)) {
                continue;
            }
            $trimmed = trim($file);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_slice(array_unique($normalized), 0, 10);
    }

    /**
     * @param  list<string>  $files
     */
    private function touchesBaseSubmodule(array $files): bool
    {
        foreach ($files as $file) {
            $lower = strtolower($file);
            if (str_starts_with($lower, 'base/') || str_contains($lower, '/base/')) {
                return true;
            }
        }

        return false;
    }

    private function clampConfidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * Strip control characters (keeps UTF-8 intact) and cap length before the
     * text reaches an LLM prompt or a stored field.
     */
    private function clean(string $value, int $max): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;

        return mb_substr(trim($stripped), 0, $max);
    }
}
