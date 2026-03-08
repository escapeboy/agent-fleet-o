<?php

namespace App\Livewire\Health;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Services\LocalLlmDiscovery;
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
            'localLlmStats' => $this->getLocalLlmStats(),
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

    private function getLocalLlmStats(): array
    {
        if (! config('local_llm.enabled', false)) {
            return ['enabled' => false, 'providers' => []];
        }

        $discovery = app(LocalLlmDiscovery::class);
        $team = auth()->user()?->currentTeam;
        $credentials = $team
            ? TeamProviderCredential::where('team_id', $team->id)
                ->whereIn('provider', ['ollama', 'openai_compatible'])
                ->where('is_active', true)
                ->get()
                ->keyBy('provider')
            : collect();

        $providers = [];
        foreach (['ollama', 'openai_compatible'] as $provider) {
            $credential = $credentials->get($provider);
            $baseUrl = $credential?->credentials['base_url'] ?? config("llm_providers.{$provider}.default_url");

            if ($baseUrl) {
                $reachable = $discovery->isReachable($provider, $baseUrl);
                $models = $reachable
                    ? $discovery->discoverModels($provider, $baseUrl, $credential?->credentials['api_key'] ?? null)
                    : [];
            } else {
                $reachable = false;
                $models = [];
            }

            $providers[$provider] = [
                'name' => config("llm_providers.{$provider}.name", $provider),
                'base_url' => $baseUrl,
                'configured' => $credential !== null,
                'reachable' => $reachable,
                'model_count' => count($models),
            ];
        }

        return ['enabled' => true, 'providers' => $providers];
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
