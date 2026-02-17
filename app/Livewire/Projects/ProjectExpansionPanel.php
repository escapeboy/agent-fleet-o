<?php

namespace App\Livewire\Projects;

use App\Domain\Project\Actions\ExpandProjectGoalAction;
use App\Domain\Project\Actions\MaterializeExpandedFeaturesAction;
use App\Domain\Project\Models\Project;
use Livewire\Component;

class ProjectExpansionPanel extends Component
{
    public Project $project;

    public string $goal = '';

    public string $context = '';

    public array $features = [];

    public ?float $costEstimate = null;

    public bool $expanding = false;

    public bool $materializing = false;

    public ?string $error = null;

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->goal = $project->description ?? '';
    }

    public function expand(): void
    {
        $this->validate([
            'goal' => 'required|string|min:10|max:2000',
        ]);

        $this->expanding = true;
        $this->error = null;
        $this->features = [];

        try {
            $action = app(ExpandProjectGoalAction::class);
            $result = $action->execute(
                goal: $this->goal,
                teamId: $this->project->team_id,
                context: $this->context ?: null,
            );

            $this->features = $result['features'];
            $this->costEstimate = $result['cost_estimate'];
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->expanding = false;
        }
    }

    public function removeFeature(int $index): void
    {
        unset($this->features[$index]);
        $this->features = array_values($this->features);
        $this->costEstimate = array_sum(array_column($this->features, 'estimated_credits'));
    }

    public function materialize(): void
    {
        if (empty($this->features)) {
            return;
        }

        $this->materializing = true;

        try {
            $action = app(MaterializeExpandedFeaturesAction::class);
            $experimentIds = $action->execute($this->project, $this->features);

            session()->flash('message', count($experimentIds).' experiments created from expanded features.');
            $this->features = [];
            $this->costEstimate = null;

            $this->redirect(route('projects.show', $this->project), navigate: true);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->materializing = false;
        }
    }

    public function render()
    {
        return view('livewire.projects.project-expansion-panel');
    }
}
