<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Agent\Models\Agent;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use Illuminate\Support\Facades\Log;

class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    public function isAvailable(string $provider): bool
    {
        $state = $this->getState($provider);

        if (! $state) {
            return true; // No circuit breaker state = available
        }

        return match ($state->state) {
            self::STATE_CLOSED => true,
            self::STATE_HALF_OPEN => true, // Allow one probe request
            self::STATE_OPEN => $this->shouldTransitionToHalfOpen($state),
            default => true,
        };
    }

    public function recordSuccess(string $provider): void
    {
        $state = $this->getOrCreateState($provider);

        if ($state->state === self::STATE_HALF_OPEN) {
            // Half-open success → close circuit
            $state->update([
                'state' => self::STATE_CLOSED,
                'failure_count' => 0,
                'success_count' => $state->success_count + 1,
                'half_open_at' => null,
                'opened_at' => null,
            ]);

            Log::info("CircuitBreaker: {$provider} closed (recovered)", [
                'provider' => $provider,
            ]);
        } else {
            $state->update([
                'success_count' => $state->success_count + 1,
            ]);
        }
    }

    public function recordFailure(string $provider): void
    {
        $state = $this->getOrCreateState($provider);

        $newFailureCount = $state->failure_count + 1;

        if ($state->state === self::STATE_HALF_OPEN) {
            // Half-open failure → reopen circuit
            $state->update([
                'state' => self::STATE_OPEN,
                'failure_count' => $newFailureCount,
                'last_failure_at' => now(),
                'opened_at' => now(),
                'half_open_at' => null,
            ]);

            Log::warning("CircuitBreaker: {$provider} reopened (half-open probe failed)", [
                'provider' => $provider,
                'failure_count' => $newFailureCount,
            ]);
        } elseif ($newFailureCount >= $state->failure_threshold) {
            // Threshold breached → open circuit
            $state->update([
                'state' => self::STATE_OPEN,
                'failure_count' => $newFailureCount,
                'last_failure_at' => now(),
                'opened_at' => now(),
            ]);

            Log::warning("CircuitBreaker: {$provider} opened (threshold reached)", [
                'provider' => $provider,
                'failure_count' => $newFailureCount,
                'threshold' => $state->failure_threshold,
            ]);
        } else {
            $state->update([
                'failure_count' => $newFailureCount,
                'last_failure_at' => now(),
            ]);
        }
    }

    public function reset(string $provider): void
    {
        $state = $this->getState($provider);

        $state?->update([
            'state' => self::STATE_CLOSED,
            'failure_count' => 0,
            'success_count' => 0,
            'last_failure_at' => null,
            'opened_at' => null,
            'half_open_at' => null,
        ]);
    }

    private function shouldTransitionToHalfOpen(CircuitBreakerState $state): bool
    {
        if (! $state->opened_at) {
            return false;
        }

        $cooldownExpired = $state->opened_at->addSeconds($state->cooldown_seconds)->isPast();

        if ($cooldownExpired) {
            $state->update([
                'state' => self::STATE_HALF_OPEN,
                'half_open_at' => now(),
            ]);

            $providerName = $state->agent?->provider ?? 'unknown';
            Log::info("CircuitBreaker: {$providerName} transitioned to half-open", [
                'provider' => $providerName,
                'cooldown_seconds' => $state->cooldown_seconds,
            ]);

            return true;
        }

        return false;
    }

    private function getState(string $provider): ?CircuitBreakerState
    {
        $agent = Agent::withoutGlobalScopes()
            ->where('provider', $provider)
            ->whereHas('circuitBreakerState')
            ->first();

        return $agent?->circuitBreakerState;
    }

    private function getOrCreateState(string $provider): CircuitBreakerState
    {
        $agent = Agent::withoutGlobalScopes()->where('provider', $provider)->first();

        if (! $agent) {
            // For unregistered providers, return a transient in-memory state (not persisted)
            $state = new CircuitBreakerState([
                'state' => self::STATE_CLOSED,
                'failure_count' => 0,
                'success_count' => 0,
                'cooldown_seconds' => 60,
                'failure_threshold' => 5,
            ]);
            $state->exists = false;

            return $state;
        }

        return CircuitBreakerState::withoutGlobalScopes()->firstOrCreate(
            ['agent_id' => $agent->id],
            [
                'team_id' => $agent->team_id,
                'state' => self::STATE_CLOSED,
                'failure_count' => 0,
                'success_count' => 0,
                'cooldown_seconds' => 60,
                'failure_threshold' => 5,
            ]
        );
    }
}
