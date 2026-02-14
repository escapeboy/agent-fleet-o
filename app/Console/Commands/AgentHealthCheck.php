<?php

namespace App\Console\Commands;

use App\Domain\Agent\Actions\HealthCheckAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use Illuminate\Console\Command;

class AgentHealthCheck extends Command
{
    protected $signature = 'agents:health-check';

    protected $description = 'Run health checks against all active AI agents';

    public function handle(HealthCheckAction $action): int
    {
        $agents = Agent::withoutGlobalScopes()->whereIn('status', [AgentStatus::Active, AgentStatus::Degraded])->get();

        if ($agents->isEmpty()) {
            $this->info('No active agents to check.');

            return self::SUCCESS;
        }

        $passed = 0;
        $failed = 0;

        foreach ($agents as $agent) {
            $result = $action->execute($agent);

            if ($result) {
                $passed++;
                $this->line("  [OK] {$agent->name} ({$agent->provider}/{$agent->model})");
            } else {
                $failed++;
                $this->warn("  [FAIL] {$agent->name} ({$agent->provider}/{$agent->model})");
            }
        }

        $this->info("Health check complete: {$passed} passed, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
