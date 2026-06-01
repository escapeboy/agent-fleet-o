<?php

namespace App\Livewire\Crews;

use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Orchestration\Exceptions\CostGateExceededException;
use App\Domain\Orchestration\Services\OrchestrationCostEstimator;
use App\Domain\Orchestration\Services\OrchestrationCostGate;
use App\Domain\Orchestration\Services\OrchestrationTierRecommender;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class CrewExecutionPage extends Component
{
    public Crew $crew;

    public string $goal = '';

    public bool $costConfirmed = false;

    /** @var array<string, mixed>|null */
    public ?array $tierRecommendation = null;

    protected function rules(): array
    {
        return [
            'goal' => 'required|min:10|max:5000',
        ];
    }

    public function mount(Crew $crew): void
    {
        $this->crew = $crew;
    }

    public function recommendTier(OrchestrationTierRecommender $recommender): void
    {
        if (! config('orchestration.tier_selector.enabled', false)) {
            return;
        }

        if (strlen(trim($this->goal)) < 10) {
            $this->addError('goal', 'Enter a goal (10+ chars) to get a recommendation.');

            return;
        }

        $this->tierRecommendation = $recommender->recommend($this->goal);
    }

    public function execute(ExecuteCrewAction $action): void
    {
        Gate::authorize('edit-content');

        $this->validate();

        if ($this->crew->status !== CrewStatus::Active) {
            $this->addError('goal', 'Crew must be active to execute. Please activate it first.');

            return;
        }

        try {
            $action->execute(
                crew: $this->crew,
                goal: $this->goal,
                teamId: auth()->user()->currentTeam?->id ?? $this->crew->team_id,
                costConfirmed: $this->costConfirmed,
            );

            session()->flash('message', 'Crew execution started.');
            $this->redirect(route('crews.show', $this->crew));
        } catch (CostGateExceededException $e) {
            // Surface the confirm-cost prompt; the banner in the view exposes the checkbox.
            $this->addError('goal', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->addError('goal', $e->getMessage());
        }
    }

    public function render()
    {
        $members = $this->crew->members()->with('agent')->orderBy('sort_order')->get();
        $coordinator = $this->crew->coordinator;
        $qaAgent = $this->crew->qaAgent;

        $estimator = app(OrchestrationCostEstimator::class);
        $gate = app(OrchestrationCostGate::class);
        $team = auth()->user()->currentTeam;
        $projected = $estimator->estimateCrew($this->crew);

        return view('livewire.crews.crew-execution-page', [
            'members' => $members,
            'coordinator' => $coordinator,
            'qaAgent' => $qaAgent,
            'costProjected' => $projected,
            'costThreshold' => $gate->thresholdFor($team),
            'costGateEnabled' => $gate->enabled(),
            'costRequiresConfirmation' => $gate->requiresConfirmation($projected, $team),
            'tierSelectorEnabled' => (bool) config('orchestration.tier_selector.enabled', false),
        ])->layout('layouts.app', ['header' => "Execute: {$this->crew->name}"]);
    }
}
