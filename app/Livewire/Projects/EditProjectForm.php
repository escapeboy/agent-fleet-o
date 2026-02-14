<?php

namespace App\Livewire\Projects;

use App\Domain\Agent\Models\Agent;
use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ScheduleFrequency;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Models\Tool;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;

class EditProjectForm extends Component
{
    public Project $project;

    // Basics
    public string $title = '';

    public string $description = '';

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
    public ?int $perRunCap = null;

    public ?int $dailyCap = null;

    public ?int $weeklyCap = null;

    public ?int $monthlyCap = null;

    // Tools & Credentials
    public array $selectedToolIds = [];

    public array $selectedCredentialIds = [];

    public function mount(Project $project): void
    {
        $this->project = $project;
        $project->load('schedule');

        $this->title = $project->title;
        $this->description = $project->description ?? '';
        $this->agentId = $project->agent_config['lead_agent_id'] ?? '';
        $this->workflowId = $project->workflow_id ?? '';

        // Schedule
        if ($schedule = $project->schedule) {
            $this->frequency = $schedule->frequency->value;
            $this->cronExpression = $schedule->cron_expression ?? '';
            $this->timezone = $schedule->timezone ?? 'UTC';
            $this->overlapPolicy = $schedule->overlap_policy->value;
            $this->maxConsecutiveFailures = $schedule->max_consecutive_failures;
        }

        // Delivery
        $delivery = $project->delivery_config;
        if ($delivery) {
            $this->deliveryChannel = $delivery['channel'] ?? 'none';
            $this->deliveryTarget = $delivery['target'] ?? '';
            $this->deliveryFormat = $delivery['format'] ?? 'summary';
        }

        // Budget
        $budget = $project->budget_config ?? [];
        $this->perRunCap = $budget['per_run_cap'] ?? null;
        $this->dailyCap = $budget['daily_cap'] ?? null;
        $this->weeklyCap = $budget['weekly_cap'] ?? null;
        $this->monthlyCap = $budget['monthly_cap'] ?? null;

        // Tools & Credentials
        $this->selectedToolIds = $project->allowed_tool_ids ?? [];
        $this->selectedCredentialIds = $project->allowed_credential_ids ?? [];
    }

    protected function rules(): array
    {
        $rules = [
            'title' => 'required|min:2|max:255',
            'description' => 'nullable|max:2000',
            'workflowId' => 'nullable|exists:workflows,id',
            'agentId' => $this->workflowId ? 'nullable' : 'required|exists:agents,id',
        ];

        if ($this->project->isContinuous()) {
            $rules['frequency'] = 'required|in:'.implode(',', array_column(ScheduleFrequency::cases(), 'value'));
            $rules['timezone'] = 'required|timezone';
            if ($this->frequency === 'cron') {
                $rules['cronExpression'] = 'required|max:100';
            }
        }

        if ($this->deliveryChannel !== 'none') {
            $rules['deliveryTarget'] = 'required|max:500';
        }

        return $rules;
    }

    public function save(): void
    {
        $this->validate();

        $budgetConfig = array_filter([
            'per_run_cap' => $this->perRunCap,
            'daily_cap' => $this->dailyCap,
            'weekly_cap' => $this->weeklyCap,
            'monthly_cap' => $this->monthlyCap,
        ]);

        $deliveryConfig = null;
        if ($this->deliveryChannel !== 'none') {
            $deliveryConfig = [
                'channel' => $this->deliveryChannel,
                'target' => $this->deliveryTarget,
                'format' => $this->deliveryFormat,
            ];
        }

        $data = [
            'title' => $this->title,
            'description' => $this->description ?: null,
            'workflow_id' => $this->workflowId ?: null,
            'agent_config' => $this->agentId ? ['lead_agent_id' => $this->agentId] : $this->project->agent_config,
            'budget_config' => $budgetConfig ?: [],
            'delivery_config' => $deliveryConfig,
        ];

        if ($this->project->isContinuous()) {
            $data['schedule'] = [
                'frequency' => $this->frequency,
                'cron_expression' => $this->frequency === 'cron' ? $this->cronExpression : null,
                'timezone' => $this->timezone,
                'overlap_policy' => $this->overlapPolicy,
                'max_consecutive_failures' => $this->maxConsecutiveFailures,
            ];
        }

        app(UpdateProjectAction::class)->execute($this->project, $data);

        // Update tools & credentials
        $this->project->update([
            'allowed_tool_ids' => ! empty($this->selectedToolIds) ? array_values($this->selectedToolIds) : null,
            'allowed_credential_ids' => ! empty($this->selectedCredentialIds) ? array_values($this->selectedCredentialIds) : null,
        ]);

        session()->flash('message', 'Project updated successfully!');
        $this->redirect(route('projects.show', $this->project));
    }

    public function render()
    {
        $agents = Agent::where('status', 'active')->orderBy('name')->get();
        $workflows = Workflow::where('status', WorkflowStatus::Active)->orderBy('name')->get(['id', 'name']);
        $frequencies = ScheduleFrequency::cases();
        $overlapPolicies = OverlapPolicy::cases();
        $tools = Tool::where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']);
        $credentials = Credential::where('status', 'active')->orderBy('name')->get(['id', 'name', 'credential_type']);

        return view('livewire.projects.edit-project-form', [
            'agents' => $agents,
            'workflows' => $workflows,
            'frequencies' => $frequencies,
            'overlapPolicies' => $overlapPolicies,
            'tools' => $tools,
            'credentials' => $credentials,
        ])->layout('layouts.app', ['header' => 'Edit: '.$this->project->title]);
    }
}
