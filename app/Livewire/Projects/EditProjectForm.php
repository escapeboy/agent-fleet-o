<?php

namespace App\Livewire\Projects;

use App\Domain\Agent\Models\Agent;
use App\Domain\Credential\Models\Credential;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ProjectExecutionMode;
use App\Domain\Project\Enums\ScheduleFrequency;
use App\Domain\Project\Models\Project;
use App\Domain\Tool\Models\Tool;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Facades\Gate;
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
    public string $executionMode = 'autonomous';

    public string $deliveryChannel = 'none';

    public string $deliveryTarget = '';

    public string $deliveryFormat = 'summary';

    // Outbound channel constraints
    public array $allowedOutboundChannels = ['email'];

    public bool $notifyOnSuccess = false;

    public bool $notifyOnFailure = true;

    // Heartbeat
    public bool $heartbeatEnabled = false;

    public ?int $heartbeatIntervalMinutes = 30;

    public ?int $heartbeatBudgetCap = null;

    public array $heartbeatContextSources = ['signals', 'metrics', 'audit'];

    // Budget
    public ?int $perRunCap = null;

    public ?int $dailyCap = null;

    public ?int $weeklyCap = null;

    public ?int $monthlyCap = null;

    // Email template
    public ?string $emailTemplateId = null;

    // Tools & Credentials
    public array $selectedToolIds = [];

    public array $selectedCredentialIds = [];

    // Quality Gates (live in project.settings JSONB)
    public bool $doneGateEnabled = false;

    public bool $doneGateKillSwitch = false;

    public function mount(Project $project): void
    {
        $this->project = $project;
        $project->load('schedule');

        $this->title = $project->title;
        $this->description = $project->description ?? '';
        $this->executionMode = $project->execution_mode->value ?? 'autonomous';
        $this->agentId = $project->agent_config['lead_agent_id'] ?? '';
        $this->workflowId = $project->workflow_id ?? '';

        // Schedule
        if ($schedule = $project->schedule) {
            $this->frequency = $schedule->frequency->value;
            $this->cronExpression = $schedule->cron_expression ?? '';
            $this->timezone = $schedule->timezone ?? 'UTC';
            $this->overlapPolicy = $schedule->overlap_policy->value;
            $this->maxConsecutiveFailures = $schedule->max_consecutive_failures;
            $this->heartbeatEnabled = $schedule->heartbeat_enabled ?? false;
            $this->heartbeatIntervalMinutes = $schedule->heartbeat_interval_minutes ?? 30;
            $this->heartbeatBudgetCap = $schedule->heartbeat_budget_cap;
            $this->heartbeatContextSources = $schedule->heartbeat_context_sources ?? ['signals', 'metrics', 'audit'];
        }

        // Delivery
        $delivery = $project->delivery_config;
        if ($delivery) {
            $this->deliveryChannel = $delivery['channel'] ?? 'none';
            $this->deliveryTarget = $delivery['target'] ?? '';
            $this->deliveryFormat = $delivery['format'] ?? 'summary';
            $this->allowedOutboundChannels = $delivery['allowed_outbound_channels'] ?? ['email'];
            $this->notifyOnSuccess = $delivery['notify_on_success'] ?? false;
            $this->notifyOnFailure = $delivery['notify_on_failure'] ?? true;
        }

        // Budget
        $budget = $project->budget_config ?? [];
        $this->perRunCap = $budget['per_run_cap'] ?? null;
        $this->dailyCap = $budget['daily_cap'] ?? null;
        $this->weeklyCap = $budget['weekly_cap'] ?? null;
        $this->monthlyCap = $budget['monthly_cap'] ?? null;

        // Email template
        $this->emailTemplateId = $project->email_template_id;

        // Tools & Credentials
        $this->selectedToolIds = $project->allowed_tool_ids ?? [];
        $this->selectedCredentialIds = $project->allowed_credential_ids ?? [];

        // Quality Gates
        $settings = $project->settings ?? [];
        $this->doneGateEnabled = (bool) ($settings['done_gate_enabled'] ?? false);
        $this->doneGateKillSwitch = (bool) ($settings['done_gate_kill_switch'] ?? false);
    }

    protected function rules(): array
    {
        $teamId = auth()->user()->current_team_id;

        $rules = [
            'title' => 'required|min:2|max:255',
            'description' => 'nullable|max:2000',
            'workflowId' => "nullable|exists:workflows,id,team_id,{$teamId}",
            'agentId' => $this->workflowId ? 'nullable' : "required|exists:agents,id,team_id,{$teamId}",
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

        $rules['allowedOutboundChannels'] = 'array';
        $rules['allowedOutboundChannels.*'] = 'string|in:email,slack,telegram,webhook';

        $rules['heartbeatEnabled'] = 'boolean';
        $rules['heartbeatIntervalMinutes'] = 'nullable|integer|in:15,30,60,120,240';
        $rules['heartbeatBudgetCap'] = 'nullable|integer|min:1';
        $rules['heartbeatContextSources'] = 'array';
        $rules['heartbeatContextSources.*'] = 'string|in:signals,metrics,audit,experiments';

        $rules['doneGateEnabled'] = 'boolean';
        $rules['doneGateKillSwitch'] = 'boolean';

        return $rules;
    }

    public function save(): void
    {
        Gate::authorize('edit-content');

        $this->validate();

        $budgetConfig = array_filter([
            'per_run_cap' => $this->perRunCap,
            'daily_cap' => $this->dailyCap,
            'weekly_cap' => $this->weeklyCap,
            'monthly_cap' => $this->monthlyCap,
        ]);

        $deliveryConfig = [
            'allowed_outbound_channels' => array_values($this->allowedOutboundChannels),
            'notify_on_success' => $this->notifyOnSuccess,
            'notify_on_failure' => $this->notifyOnFailure,
        ];
        if ($this->deliveryChannel !== 'none') {
            $deliveryConfig['channel'] = $this->deliveryChannel;
            $deliveryConfig['target'] = $this->deliveryTarget;
            $deliveryConfig['format'] = $this->deliveryFormat;
        }

        $data = [
            'title' => $this->title,
            'description' => $this->description ?: null,
            'execution_mode' => ProjectExecutionMode::from($this->executionMode),
            'workflow_id' => $this->workflowId ?: null,
            'agent_config' => $this->agentId ? ['lead_agent_id' => $this->agentId] : $this->project->agent_config,
            'budget_config' => $budgetConfig ?: [],
            'delivery_config' => $deliveryConfig,
            'allowed_tool_ids' => array_values($this->selectedToolIds),
            'allowed_credential_ids' => array_values($this->selectedCredentialIds),
            'email_template_id' => $this->emailTemplateId ?: null,
            'settings' => [
                'done_gate_enabled' => $this->doneGateEnabled,
                'done_gate_kill_switch' => $this->doneGateKillSwitch,
            ],
        ];

        if ($this->project->isContinuous()) {
            $data['schedule'] = [
                'frequency' => $this->frequency,
                'cron_expression' => $this->frequency === 'cron' ? $this->cronExpression : null,
                'timezone' => $this->timezone,
                'overlap_policy' => $this->overlapPolicy,
                'max_consecutive_failures' => $this->maxConsecutiveFailures,
                'heartbeat_enabled' => $this->heartbeatEnabled,
                'heartbeat_interval_minutes' => $this->heartbeatEnabled ? $this->heartbeatIntervalMinutes : null,
                'heartbeat_budget_cap' => $this->heartbeatBudgetCap ?: null,
                'heartbeat_context_sources' => $this->heartbeatContextSources,
            ];
        }

        app(UpdateProjectAction::class)->execute($this->project, $data);

        session()->flash('message', 'Project updated successfully!');
        $this->redirect(route('projects.show', $this->project));
    }

    public function getSchedulePreviewProperty(): array
    {
        if (! $this->project->isContinuous() || ! $this->project->schedule) {
            return [];
        }

        return $this->project->schedule->getNextRunTimes(5);
    }

    public function render()
    {
        $agents = Agent::where('status', 'active')->orderBy('name')->get();
        $workflows = Workflow::where('status', WorkflowStatus::Active)->orderBy('name')->get(['id', 'name']);
        $frequencies = ScheduleFrequency::cases();
        $overlapPolicies = OverlapPolicy::cases();
        $tools = Tool::where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']);
        $credentials = Credential::where('status', 'active')->orderBy('name')->get(['id', 'name', 'credential_type']);
        $emailTemplates = EmailTemplate::where('status', 'active')->orderBy('name')->get(['id', 'name', 'subject']);

        return view('livewire.projects.edit-project-form', [
            'agents' => $agents,
            'workflows' => $workflows,
            'frequencies' => $frequencies,
            'overlapPolicies' => $overlapPolicies,
            'tools' => $tools,
            'credentials' => $credentials,
            'emailTemplates' => $emailTemplates,
            'executionModes' => ProjectExecutionMode::cases(),
            'schedulePreview' => $this->schedulePreview,
        ])->layout('layouts.app', ['header' => 'Edit: '.$this->project->title]);
    }
}
