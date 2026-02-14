<?php

namespace App\Livewire\Health;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Livewire\Component;

class HealthPage extends Component
{
    public function render()
    {
        return view('livewire.health.health-page', [
            'agents' => Agent::with('circuitBreakerState')->get(),
            'queueStats' => $this->getQueueStats(),
            'recentErrors' => $this->getRecentErrors(),
            'spendStats' => $this->getSpendStats(),
        ])->layout('layouts.app', ['header' => 'System Health']);
    }

    private function getQueueStats(): array
    {
        $queues = ['critical', 'ai-calls', 'experiments', 'outbound', 'metrics', 'default'];
        $stats = [];

        foreach ($queues as $queue) {
            try {
                $size = Redis::connection('queue')->llen("queues:{$queue}");
                $delayed = Redis::connection('queue')->zcard("queues:{$queue}:delayed");
                $reserved = Redis::connection('queue')->zcard("queues:{$queue}:reserved");
            } catch (\Throwable) {
                $size = $delayed = $reserved = 0;
            }

            $stats[$queue] = [
                'size' => $size,
                'delayed' => $delayed,
                'reserved' => $reserved,
            ];
        }

        return $stats;
    }

    private function getRecentErrors(): Collection
    {
        return ExperimentStage::where('status', 'failed')
            ->with('experiment')
            ->latest()
            ->limit(10)
            ->get();
    }

    private function getSpendStats(): array
    {
        $today = CreditLedger::where('type', 'spend')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('amount');

        $thisHour = CreditLedger::where('type', 'spend')
            ->where('created_at', '>=', now()->startOfHour())
            ->sum('amount');

        $totalBudgetCap = Experiment::whereNotIn('status', ['completed', 'killed', 'discarded', 'expired'])
            ->sum('budget_cap_credits');

        $totalSpent = Experiment::sum('budget_spent_credits');

        return [
            'today' => abs($today),
            'this_hour' => abs($thisHour),
            'total_budget_cap' => $totalBudgetCap,
            'total_spent' => $totalSpent,
        ];
    }
}
