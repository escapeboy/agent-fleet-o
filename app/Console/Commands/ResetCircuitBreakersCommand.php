<?php

namespace App\Console\Commands;

use App\Infrastructure\AI\Models\CircuitBreakerState;
use Illuminate\Console\Command;

class ResetCircuitBreakersCommand extends Command
{
    protected $signature = 'circuit-breakers:reset';

    protected $description = 'Reset all open circuit breakers to closed (run after deploy to clear deploy-induced failures)';

    public function handle(): int
    {
        $open = CircuitBreakerState::withoutGlobalScopes()
            ->where('state', 'open')
            ->with('agent:id,name,provider')
            ->get();

        if ($open->isEmpty()) {
            $this->info('No open circuit breakers.');

            return self::SUCCESS;
        }

        foreach ($open as $state) {
            $state->update([
                'state' => 'closed',
                'failure_count' => 0,
                'success_count' => 0,
                'last_failure_at' => null,
                'opened_at' => null,
                'half_open_at' => null,
            ]);

            $name = $state->agent?->name ?? $state->agent_id;
            $provider = $state->agent?->provider ?? 'unknown';
            $this->line("  [RESET] {$name} ({$provider})");
        }

        $this->info("Reset {$open->count()} circuit breaker(s).");

        return self::SUCCESS;
    }
}
