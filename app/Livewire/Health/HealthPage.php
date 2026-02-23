<?php

namespace App\Livewire\Health;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Signal\Models\Signal;
use App\Models\Connector;
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
            'stuckExperiments' => $this->getStuckExperiments(),
            'connectorStats' => $this->getConnectorStats(),
        ])->layout('layouts.app', ['header' => 'System Health']);
    }

    public function retryExperiment(string $experimentId): void
    {
        $experiment = Experiment::find($experimentId);
        if (! $experiment || $experiment->status->isTerminal()) {
            $this->dispatch('notify', message: 'Experiment not found or already finished.', type: 'error');

            return;
        }

        // Touch to reset the timeout clock
        $experiment->touch();
        $this->dispatch('notify', message: "Recovery triggered for \"{$experiment->title}\".", type: 'success');
    }

    public function killExperiment(string $experimentId): void
    {
        $experiment = Experiment::find($experimentId);
        if (! $experiment || $experiment->status->isTerminal()) {
            $this->dispatch('notify', message: 'Experiment not found or already finished.', type: 'error');

            return;
        }

        try {
            app(TransitionExperimentAction::class)->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Killed,
                reason: 'Manually killed from Health dashboard (stuck experiment)',
            );
            $this->dispatch('notify', message: "Experiment \"{$experiment->title}\" has been killed.", type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Failed to kill experiment: '.$e->getMessage(), type: 'error');
        }
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

    private function getStuckExperiments(): Collection
    {
        $timeouts = config('experiments.recovery.timeouts', []);
        $processingStates = [
            ExperimentStatus::Scoring,
            ExperimentStatus::Planning,
            ExperimentStatus::Building,
            ExperimentStatus::Executing,
            ExperimentStatus::CollectingMetrics,
            ExperimentStatus::Evaluating,
        ];

        $stuck = collect();

        foreach ($processingStates as $state) {
            $timeoutSeconds = $timeouts[$state->value] ?? 900;
            $cutoff = now()->subSeconds($timeoutSeconds);

            $experiments = Experiment::where('status', $state)
                ->where('updated_at', '<', $cutoff)
                ->get();

            foreach ($experiments as $experiment) {
                $stage = ExperimentStage::where('experiment_id', $experiment->id)
                    ->where('stage', $state->value)
                    ->orderByDesc('iteration')
                    ->first();

                $stuck->push((object) [
                    'experiment' => $experiment,
                    'state' => $state->value,
                    'stuck_since' => $experiment->updated_at,
                    'stuck_duration' => $experiment->updated_at->diffForHumans(now(), true),
                    'recovery_attempts' => $stage?->recovery_attempts ?? 0,
                    'last_recovery_at' => $stage?->last_recovery_at,
                ]);
            }
        }

        return $stuck->sortByDesc('recovery_attempts');
    }

    private function getConnectorStats(): Collection
    {
        $connectors = Connector::where('type', 'input')
            ->where('status', 'active')
            ->get();

        return $connectors->map(function (Connector $connector) {
            $signalsToday = Signal::where('source_type', $connector->driver)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            return (object) [
                'id' => $connector->id,
                'name' => $connector->name,
                'driver' => $connector->driver,
                'last_success_at' => $connector->last_success_at,
                'last_error_at' => $connector->last_error_at,
                'last_error_message' => $connector->last_error_message,
                'signals_24h' => $signalsToday,
                'is_healthy' => ! $connector->last_error_at
                    || ($connector->last_success_at && $connector->last_success_at > $connector->last_error_at),
            ];
        });
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
