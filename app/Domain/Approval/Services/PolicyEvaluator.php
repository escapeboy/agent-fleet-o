<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\DTOs\PolicyVerdict;
use App\Domain\Approval\DTOs\ProposalContext;
use App\Domain\Approval\DTOs\ResolvedPolicy;
use Illuminate\Support\Str;

/**
 * Applies a resolved policy's rules to a proposal context and returns a
 * verdict. Pure and deterministic (the only outside read is the cap meter,
 * itself deterministic over the DB). A policy may only *narrow* autonomy:
 * the strongest verdict it can hand out is AllowAuto, and it reaches that
 * only when every rule is satisfied AND the policy explicitly opts into
 * auto-execution. Anything uncertain falls back to RequireHuman.
 */
class PolicyEvaluator
{
    /**
     * Risk ordering, ascending. `critical` is never auto-approvable.
     *
     * @var array<string, int>
     */
    private const RISK_RANK = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    public function __construct(
        private readonly PolicyCapMeter $capMeter,
    ) {}

    public function evaluate(ResolvedPolicy $resolved, ProposalContext $ctx): PolicyVerdict
    {
        $rules = $resolved->rules();
        $effectiveRisk = strtolower($ctx->riskLevel);

        // 1. Hard deny list — e.g. migrations are never auto-runnable.
        $denied = (array) ($rules['denied_target_types'] ?? []);
        if (in_array($ctx->targetType, $denied, true)) {
            return PolicyVerdict::deny(
                "Target type '{$ctx->targetType}' is on the policy deny list.",
                $effectiveRisk,
            );
        }

        // 2. Allow list (when set) — anything outside it is held, not denied.
        $allowed = $rules['allowed_target_types'] ?? null;
        if (is_array($allowed) && ! in_array($ctx->targetType, $allowed, true)) {
            return PolicyVerdict::requireHuman(
                "Target type '{$ctx->targetType}' is not in the policy allow list.",
                $effectiveRisk,
            );
        }

        // 3. Sensitive paths raise the effective risk and force review (they
        //    are instructions to be careful, not a hard block).
        $matchedPath = $this->matchSensitivePath($rules, $ctx->paths);
        if ($matchedPath !== null) {
            $effectiveRisk = $this->raise($effectiveRisk, 'high');

            return PolicyVerdict::requireHuman(
                "Touches sensitive path '{$matchedPath}' — held for human review.",
                $effectiveRisk,
            );
        }

        // 4. Spend / frequency caps — exceeding holds for review, never drops.
        $caps = $this->checkCaps($rules, $ctx, (string) $resolved->policy->team_id);
        if ($caps['exceeded']) {
            return PolicyVerdict::requireHuman($caps['reason'], $effectiveRisk, $caps);
        }

        // 5. Critical risk is always human, regardless of ceiling.
        if ($effectiveRisk === 'critical') {
            return PolicyVerdict::requireHuman(
                'Critical-risk action always requires human review.',
                $effectiveRisk,
                $caps,
            );
        }

        // 6. Risk ceiling — above the auto-approvable ceiling → review.
        $ceiling = strtolower((string) ($rules['risk_ceiling'] ?? 'low'));
        if ($this->rank($effectiveRisk) > $this->rank($ceiling)) {
            return PolicyVerdict::requireHuman(
                "Risk '{$effectiveRisk}' exceeds the policy ceiling '{$ceiling}'.",
                $effectiveRisk,
                $caps,
            );
        }

        // 7. Auto-execution is an explicit per-policy opt-in gated by the
        //    rubric total when one is available.
        $auto = (array) ($rules['auto_execute'] ?? []);
        if (($auto['enabled'] ?? false) === true) {
            $threshold = (int) ($auto['threshold'] ?? 18);
            if ($ctx->rubricTotal === null || $ctx->rubricTotal >= $threshold) {
                return PolicyVerdict::allowAuto(
                    "Within policy ceiling '{$ceiling}' and auto-execute opted in"
                    .($ctx->rubricTotal !== null ? " (rubric {$ctx->rubricTotal} ≥ {$threshold})" : '').'.',
                    $effectiveRisk,
                    $caps,
                );
            }

            return PolicyVerdict::requireHuman(
                "Rubric score {$ctx->rubricTotal} below policy auto-execute threshold {$threshold}.",
                $effectiveRisk,
                $caps,
            );
        }

        return PolicyVerdict::requireHuman(
            'Policy does not opt into auto-execution for this action.',
            $effectiveRisk,
            $caps,
        );
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $paths
     */
    private function matchSensitivePath(array $rules, array $paths): ?string
    {
        $patterns = (array) ($rules['sensitive_paths'] ?? []);

        foreach ($paths as $path) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{exceeded: bool, reason: string, spend?: array<string, mixed>, frequency?: array<string, mixed>}
     */
    private function checkCaps(array $rules, ProposalContext $ctx, string $teamId): array
    {
        $out = ['exceeded' => false, 'reason' => ''];

        $spendCap = $rules['spend_cap'] ?? null;
        if (is_array($spendCap) && isset($spendCap['credits'])) {
            $window = (string) ($spendCap['window'] ?? 'day');
            $spent = $this->capMeter->spendInWindow($teamId, $ctx->agentId, $window);
            $projected = $spent + (float) ($ctx->estimatedCredits ?? 0);
            $out['spend'] = ['spent' => $spent, 'cap' => (float) $spendCap['credits'], 'window' => $window];
            if ($projected > (float) $spendCap['credits']) {
                return ['exceeded' => true, 'reason' => "Spend cap exceeded: {$projected} > {$spendCap['credits']} credits/{$window}.", 'spend' => $out['spend']];
            }
        }

        $freqCap = $rules['frequency_cap'] ?? null;
        if (is_array($freqCap) && isset($freqCap['count'])) {
            $window = (string) ($freqCap['window'] ?? 'day');
            $count = $this->capMeter->countInWindow($teamId, $ctx->agentId, $window);
            $out['frequency'] = ['count' => $count, 'cap' => (int) $freqCap['count'], 'window' => $window];
            if ($count >= (int) $freqCap['count']) {
                return ['exceeded' => true, 'reason' => "Frequency cap reached: {$count} ≥ {$freqCap['count']} actions/{$window}.", 'frequency' => $out['frequency']];
            }
        }

        return $out;
    }

    private function rank(string $risk): int
    {
        return self::RISK_RANK[strtolower($risk)] ?? self::RISK_RANK['high'];
    }

    private function raise(string $risk, string $floor): string
    {
        return $this->rank($risk) >= $this->rank($floor) ? $risk : $floor;
    }
}
