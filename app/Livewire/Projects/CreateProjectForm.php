<?php

namespace App\Livewire\Projects;

use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Enums\ScheduleFrequency;
use Livewire\Component;

class CreateProjectForm extends Component
{
    // Basics
    public string $title = '';
    public string $description = '';
    public string $type = 'one_shot';

    // Team
    public string $agentId = '';

    // Schedule (continuous only)
    public string $frequency = 'daily';
    public string $cronExpression = '';
    public string $timezone = 'UTC';
    public string $overlapPolicy = 'skip';
    public int $maxConsecutiveFailures = 3;

    // Budget
    public ?int $dailyCap = null;
    public ?int $weeklyCap = null;
    public ?int $monthlyCap = null;
    public ?int $perRunCap = null;

    // Milestones
    public array $milestones = [];

    protected function rules(): array
    {
        $rules = [
            'title' => 'required|min:2|max:255',
            'description' => 'required|max:2000',
            'type' => 'required|in:one_shot,continuous',
            'agentId' => 'required|exists:agents,id',
        ];

        if ($this->type === 'continuous') {
            $rules['frequency'] = 'required|in:' . implode(',', array_column(ScheduleFrequency::cases(), 'value'));
            $rules['timezone'] = 'required|timezone';
            if ($this->frequency === 'cron') {
                $rules['cronExpression'] = 'required|max:100';
            }
        }

        return $rules;
    }

    public function addMilestone(): void
    {
        $this->milestones[] = ['title' => '', 'target_value' => null, 'target_metric' => 'runs'];
    }

    public function removeMilestone(int $index): void
    {
        unset($this->milestones[$index]);
        $this->milestones = array_values($this->milestones);
    }

    public function save(): void
    {
        $this->validate();

        $team = auth()->user()->currentTeam();

        $budgetConfig = array_filter([
            'daily_cap' => $this->dailyCap,
            'weekly_cap' => $this->weeklyCap,
            'monthly_cap' => $this->monthlyCap,
            'per_run_cap' => $this->perRunCap,
        ]);

        $scheduleConfig = null;
        if ($this->type === 'continuous') {
            $scheduleConfig = [
                'frequency' => $this->frequency,
                'cron_expression' => $this->frequency === 'cron' ? $this->cronExpression : null,
                'timezone' => $this->timezone,
                'overlap_policy' => $this->overlapPolicy,
                'max_consecutive_failures' => $this->maxConsecutiveFailures,
            ];
        }

        $milestoneData = array_filter(
            $this->milestones,
            fn ($m) => ! empty($m['title'])
        );

        app(CreateProjectAction::class)->execute(
            teamId: $team->id,
            userId: auth()->id(),
            title: $this->title,
            description: $this->description,
            type: ProjectType::from($this->type),
            agentId: $this->agentId,
            budgetConfig: $budgetConfig ?: [],
            scheduleConfig: $scheduleConfig,
            milestones: array_values($milestoneData),
        );

        session()->flash('message', 'Project created successfully!');
        $this->redirect(route('projects.index'));
    }

    public function render()
    {
        $agents = Agent::where('status', 'active')->orderBy('name')->get();
        $frequencies = ScheduleFrequency::cases();
        $overlapPolicies = OverlapPolicy::cases();

        return view('livewire.projects.create-project-form', [
            'agents' => $agents,
            'frequencies' => $frequencies,
            'overlapPolicies' => $overlapPolicies,
        ])->layout('layouts.app', ['header' => 'Create Project']);
    }
}
