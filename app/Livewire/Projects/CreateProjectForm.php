<?php

namespace App\Livewire\Projects;

use App\Domain\Agent\Models\Agent;
use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ScheduleFrequency;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Models\Tool;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;

class CreateProjectForm extends Component
{
    // Basics
    public string $title = '';

    public string $description = '';

    public string $type = 'one_shot';

    // Team
    public string $agentId = '';

    public string $workflowId = '';

    // Schedule (continuous only)
    public string $frequency = 'daily';

    public string $cronExpression = '';

    public string $timezone = 'UTC';

    public string $overlapPolicy = 'skip';

    public int $maxConsecutiveFailures = 3;

    // Delivery
    public string $deliveryChannel = 'none';

    public string $deliveryTarget = '';

    public string $deliveryFormat = 'summary';

    // Budget
    public ?int $dailyCap = null;

    public ?int $weeklyCap = null;

    public ?int $monthlyCap = null;

    public ?int $perRunCap = null;

    // Milestones
    public array $milestones = [];

    // Dependencies (predecessor projects)
    public array $dependencies = [];

    // Tools & Credentials
    public array $selectedToolIds = [];

    public array $selectedCredentialIds = [];

    protected function rules(): array
    {
        $rules = [
            'title' => 'required|min:2|max:255',
            'description' => 'required|max:2000',
            'type' => 'required|in:one_shot,continuous',
            'agentId' => $this->workflowId ? 'nullable' : 'required|exists:agents,id',
            'workflowId' => 'nullable|exists:workflows,id',
        ];

        if ($this->deliveryChannel !== 'none') {
            $rules['deliveryTarget'] = 'required|max:500';
        }

        if ($this->type === 'continuous') {
            $rules['frequency'] = 'required|in:'.implode(',', array_column(ScheduleFrequency::cases(), 'value'));
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

    public function addDependency(): void
    {
        $this->dependencies[] = [
            'depends_on_id' => '',
            'alias' => '',
            'reference_type' => 'latest_run',
            'is_required' => true,
        ];
    }

    public function removeDependency(int $index): void
    {
        unset($this->dependencies[$index]);
        $this->dependencies = array_values($this->dependencies);
    }

    public function updatedDependencies($value, $key): void
    {
        // Auto-generate alias from project title when selecting a project
        if (str_ends_with($key, '.depends_on_id') && $value) {
            $index = (int) explode('.', $key)[0];
            $project = Project::find($value);
            if ($project && empty($this->dependencies[$index]['alias'])) {
                $this->dependencies[$index]['alias'] = str($project->title)->slug('_')->limit(50)->toString();
            }
        }
    }

    public function save(): void
    {
        $this->validate();

        $team = auth()->user()->currentTeam;

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
            fn ($m) => ! empty($m['title']),
        );

        $dependencyData = array_filter(
            $this->dependencies,
            fn ($d) => ! empty($d['depends_on_id']),
        );

        $deliveryConfig = null;
        if ($this->deliveryChannel !== 'none') {
            $deliveryConfig = [
                'channel' => $this->deliveryChannel,
                'target' => $this->deliveryTarget,
                'format' => $this->deliveryFormat,
            ];
        }

        $project = app(CreateProjectAction::class)->execute(
            userId: auth()->id(),
            title: $this->title,
            type: $this->type,
            description: $this->description,
            workflowId: $this->workflowId ?: null,
            agentConfig: $this->agentId ? ['lead_agent_id' => $this->agentId] : [],
            budgetConfig: $budgetConfig ?: [],
            schedule: $scheduleConfig,
            milestones: array_values($milestoneData),
            dependencies: array_values($dependencyData),
            teamId: $team->id,
            deliveryConfig: $deliveryConfig,
        );

        // Assign tools & credentials
        if (! empty($this->selectedToolIds)) {
            $project->update(['allowed_tool_ids' => array_values($this->selectedToolIds)]);
        }
        if (! empty($this->selectedCredentialIds)) {
            $project->update(['allowed_credential_ids' => array_values($this->selectedCredentialIds)]);
        }

        session()->flash('message', 'Project created successfully!');
        $this->redirect(route('projects.index'));
    }

    public function render()
    {
        $agents = Agent::where('status', 'active')->orderBy('name')->get();
        $workflows = Workflow::where('status', WorkflowStatus::Active)->orderBy('name')->get(['id', 'name']);
        $frequencies = ScheduleFrequency::cases();
        $overlapPolicies = OverlapPolicy::cases();
        $availableProjects = Project::orderBy('title')
            ->whereHas('runs', fn ($q) => $q->where('status', 'completed'))
            ->orWhere('status', '!=', 'archived')
            ->orderBy('title')
            ->get(['id', 'title', 'type', 'status']);
        $tools = Tool::where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']);
        $credentials = Credential::where('status', 'active')->orderBy('name')->get(['id', 'name', 'credential_type']);

        return view('livewire.projects.create-project-form', [
            'agents' => $agents,
            'workflows' => $workflows,
            'frequencies' => $frequencies,
            'overlapPolicies' => $overlapPolicies,
            'availableProjects' => $availableProjects,
            'tools' => $tools,
            'credentials' => $credentials,
        ])->layout('layouts.app', ['header' => 'Create Project']);
    }
}
