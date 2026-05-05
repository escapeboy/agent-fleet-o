<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services\PageHelp;

use App\Domain\Agent\Models\Agent;

/**
 * Dynamic page-help for agents.show:
 *   - circuit_breaker open/half-open → recovery-focused steps
 *   - agent disabled → activation reminder
 *   - default (healthy) → returns nothing, falls back to static help
 */
final class AgentDetailHelpResolver
{
    /**
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>|null
     */
    public function __invoke(array $routeParameters): ?array
    {
        $agent = $routeParameters['agent'] ?? null;
        if (! $agent instanceof Agent) {
            return null;
        }

        // Avoid extra queries: load relation lazily once.
        $cb = $agent->circuitBreakerState;

        if ($cb !== null && in_array($cb->state, ['open', 'half_open'], true)) {
            return [
                'description' => sprintf(
                    'This agent is currently %s by the circuit breaker after %d consecutive failures. New runs are blocked until the breaker closes.',
                    $cb->state === 'open' ? 'paused' : 'half-open',
                    (int) ($cb->failure_count ?? 0),
                ),
                'steps' => [
                    'Wait for the cooldown to expire (state will move to half-open, then closed)',
                    'Check the experiment that triggered the failures — it usually points at the root cause',
                    'If the failure was a provider/credential issue, fix it before the breaker re-opens',
                    'For persistent issues, switch the agent to a different provider in Team Settings',
                ],
                'tips' => [
                    'The breaker auto-resets when the cooldown expires — no manual intervention needed for transient outages',
                    'Repeated failures after a half-open probe will re-open the breaker for the same cooldown',
                ],
            ];
        }

        if (method_exists($agent, 'isDisabled') && $agent->isDisabled()) {
            return [
                'description' => 'This agent is currently disabled and will not pick up new work. Re-enable it from the actions menu when ready.',
                'steps' => [
                    'Review the recent failed runs (if any) to confirm the agent is healthy',
                    'Click "Enable" to bring the agent back online',
                    'Optionally pin a specific provider/model in Team Settings to avoid the failing one',
                ],
            ];
        }

        return null;
    }
}
