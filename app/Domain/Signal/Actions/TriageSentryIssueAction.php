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
 * fix for actionable issues. Suspect files are routed to a single target repo
 * (parent or base submodule); mixed cases fall back to investigate-only.
 * Critical issues are alerted immediately when the flag is on.
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

    /**
     * Map of repository kind → GitHub `owner/repo` full name.
     *
     * `parent` covers cloud-only suspect_files; `base` covers the open-core
     * submodule (paths starting with `base/`). Phase 1 routes delegation to
     * the resolved target so the fixing agent clones/opens PRs against the
     * right repo.
     */
    private const TARGET_REPOSITORIES = [
        'parent' => 'escapeboy/agent-fleet',
        'base' => 'escapeboy/agent-fleet-o',
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

        // Sentry signals arrive through the integration poller, which wraps the
        // driver item — the actual Sentry issue lives under payload['payload'].
        $raw = $signal->payload ?? [];
        $payload = (isset($raw['payload']) && is_array($raw['payload'])) ? $raw['payload'] : $raw;
        $investigation = $this->investigate($payload, $signal, $team);
        $tier = $this->classifier->classify(
            $investigation['suspect_files'],
            $investigation['estimated_diff_lines'],
        );

        // Per-signal critical alerts are off by default — a batch of 15 critical
        // issues would otherwise send 15 Telegrams. The criticals are surfaced in
        // the single per-run digest instead. Set sentry_watchdog.critical_immediate
        // to true to restore the legacy alert-per-signal behaviour.
        if ($investigation['is_critical'] && (bool) config('sentry_watchdog.critical_immediate', false)) {
            $this->notifyCritical->execute($signal);
        }

        // Phase 0 — read-only: investigate and report, never delegate.
        if (config('sentry_watchdog.mode') !== 'phase1') {
            return $this->investigateOnlyResult($signal, $tier, $investigation);
        }

        // Phase 1 — delegate an autonomous fix only for actionable, confident
        // issues. Suspect files are now bucketed into a single target repo:
        // pure-parent → escapeboy/agent-fleet, pure-base → escapeboy/agent-fleet-o.
        // Mixed (files in both repos) cannot be fixed in a single PR — drop to
        // investigate-only with a "mixed" reason so the digest surfaces it.
        $threshold = (float) config('sentry_watchdog.confidence_threshold', 0.7);
        $target = $investigation['suspect_files'] !== []
            ? $this->resolveTargetRepository($investigation['suspect_files'])
            : null;

        if ($investigation['suspect_files'] !== [] && $target === null) {
            $mixedInvestigation = array_merge($investigation, [
                'suspect_files' => ['mixed suspect_files span both repos; requires manual fix'],
            ]);

            return $this->investigateOnlyResult($signal, $tier, $mixedInvestigation);
        }

        $actionable = $target !== null
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
                // target_repository is consumed by DelegateBugReportToAgentAction so
                // the fixing agent's thesis knows which repo to clone / open the PR in.
                'target_repository' => $target['full_name'],
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
    private function investigate(array $payload, Signal $signal, Team $team): array
    {
        $default = [
            'root_cause' => 'Investigation unavailable — see Sentry issue directly.',
            'confidence' => 0.0,
            'suspect_files' => [],
            'estimated_diff_lines' => 0,
            'is_critical' => $this->looksCritical($payload),
            'summary' => $this->clean((string) ($payload['title'] ?? 'Sentry issue'), 200),
        ];

        // Watchdog triage runs on the dedicated platform classification
        // key when set (config/ai.php classification), else per-team resolution.
        $provider = (string) config('ai.classification.provider');
        $model = (string) config('ai.classification.model');
        if ($provider === '' || $model === '') {
            try {
                $resolved = $this->providerResolver->resolve(team: $team, purpose: 'sentry-triage');
                $provider = $resolved['provider'];
                $model = $resolved['model'];
            } catch (\Throwable $e) {
                $this->recordTriageError($signal, 'provider_resolution_failed', $e->getMessage(), null, null, null);

                return $default;
            }
        }

        $rawResponse = null;
        try {
            $rawResponse = $this->callGateway($provider, $model, $payload, $team, retry: false);
            $parsed = $this->extractJson($rawResponse);

            // One retry with a stricter "JSON only" reminder when the first
            // attempt is unparseable. Most parse failures on Groq are markdown
            // prose explanations bracketing the JSON object — a second pass
            // with an explicit reminder recovers ~all of them, and the cost
            // (~80 output tokens) is far cheaper than burning the slot on a
            // defaulted triage that says nothing useful.
            if ($parsed === null) {
                Log::info('Sentry watchdog triage: first parse failed, retrying with stricter prompt', [
                    'signal_id' => $signal->id,
                    'team_id' => $team->id,
                    'raw_excerpt' => mb_substr($rawResponse, 0, 200),
                ]);
                $rawResponse = $this->callGateway($provider, $model, $payload, $team, retry: true);
                $parsed = $this->extractJson($rawResponse);
            }
        } catch (\Throwable $e) {
            Log::warning('Sentry watchdog investigation failed', [
                'team_id' => $team->id,
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
            $this->recordTriageError($signal, 'gateway_exception', $e->getMessage(), $rawResponse, $provider, $model);

            return $default;
        }

        if ($parsed === null) {
            $this->recordTriageError($signal, 'parse_failure', 'extractJson returned null on both attempts', $rawResponse, $provider, $model);

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callGateway(string $provider, string $model, array $payload, Team $team, bool $retry): string
    {
        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $this->systemPrompt($retry),
            userPrompt: $this->userPrompt($payload),
            maxTokens: 1024,
            userId: Team::ownerIdFor($team->id),
            teamId: $team->id,
            purpose: 'sentry-triage',
            temperature: 0.2,
        );

        return $this->gateway->complete($request)->content;
    }

    /**
     * Persist the failure mode + raw LLM output onto signal.payload so the
     * defaulted triage is no longer a silent outcome: the next watchdog run,
     * digest review, or audit can see exactly which stage failed and what
     * the model emitted. Truncated to 2KB to keep the JSONB row bounded.
     */
    private function recordTriageError(
        Signal $signal,
        string $stage,
        string $message,
        ?string $rawResponse,
        ?string $provider,
        ?string $model,
    ): void {
        $payload = $signal->payload ?? [];
        $payload['sentry_watchdog_triage_error'] = [
            'stage' => $stage,
            'message' => mb_substr($message, 0, 500),
            'raw_response' => $rawResponse !== null ? mb_substr($rawResponse, 0, 2000) : null,
            'provider' => $provider,
            'model' => $model,
            'occurred_at' => now()->toIso8601String(),
        ];
        $signal->payload = $payload;
        $signal->save();
    }

    private function systemPrompt(bool $retry = false): string
    {
        $base = 'You are a senior engineer triaging a production error from Sentry. '
            .'Identify the most likely root cause and the repository files that would need to change. '
            .'Respond with ONLY a single JSON object, no prose, with keys: '
            .'root_cause (string), confidence (number 0-1), suspect_files (array of repo-relative paths), '
            .'estimated_diff_lines (integer), is_critical (boolean — true for data loss, outages, or auth/billing breakage), '
            .'summary (string, one sentence).';

        if ($retry) {
            $base .= ' IMPORTANT: your previous reply could not be parsed. Output the JSON object only — '
                .'no leading text, no trailing text, no markdown code fences, no commentary. The very first '
                .'character of your reply must be "{" and the very last character must be "}".';
        }

        return $base;
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
     * Bucket suspect_files into a single target repository.
     *
     * `base/...` paths belong to the open-core submodule (`agent-fleet-o`);
     * everything else belongs to the cloud parent (`agent-fleet`). A single
     * PR cannot span both repos, so mixed lists return null and the caller
     * falls back to investigate-only.
     *
     * @param  list<string>  $files
     * @return array{kind: 'base'|'parent', full_name: string}|null
     */
    private function resolveTargetRepository(array $files): ?array
    {
        if ($files === []) {
            return null;
        }

        $base = 0;
        $parent = 0;
        foreach ($files as $file) {
            $lower = strtolower($file);
            if (str_starts_with($lower, 'base/')) {
                $base++;
            } else {
                $parent++;
            }
        }

        if ($base > 0 && $parent > 0) {
            return null;
        }

        $kind = $base > 0 ? 'base' : 'parent';

        return ['kind' => $kind, 'full_name' => self::TARGET_REPOSITORIES[$kind]];
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
