<?php

namespace App\Livewire\Workflows;

use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ScheduleFrequency;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Livewire\Component;

class ScheduleWorkflowForm extends Component
{
    // Basics
    public string $title = '';

    public string $workflowId = '';

    public string $description = '';

    // Schedule
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

    // Options
    public bool $runImmediately = true;

    public bool $activateOnSave = true;

    public function mount(?string $workflow = null): void
    {
        if ($workflow) {
            $wf = Workflow::find($workflow);
            if ($wf) {
                $this->workflowId = $wf->id;
                $this->title = $wf->name;
                $this->description = $wf->description ?? '';
            }
        }
    }

    public function updatedWorkflowId(string $value): void
    {
        if ($value && empty($this->title)) {
            $wf = Workflow::find($value);
            if ($wf) {
                $this->title = $wf->name;
            }
        }
    }

    protected function rules(): array
    {
        $rules = [
            'title' => 'required|min:2|max:255',
            'workflowId' => 'required|exists:workflows,id',
            'frequency' => 'required|in:'.implode(',', array_column(ScheduleFrequency::cases(), 'value')),
            'timezone' => 'required|timezone',
            'overlapPolicy' => 'required|in:'.implode(',', array_column(OverlapPolicy::cases(), 'value')),
            'maxConsecutiveFailures' => 'required|integer|min:1|max:100',
            'deliveryChannel' => 'required|in:none,email,slack,telegram,webhook',
        ];

        if ($this->frequency === 'cron') {
            $rules['cronExpression'] = 'required|max:100';
        }

        if ($this->deliveryChannel !== 'none') {
            $rules['deliveryTarget'] = 'required|max:500';
        }

        return $rules;
    }

    public function save(): void
    {
        $this->validate();

        // Verify workflow is active
        $workflow = Workflow::withoutGlobalScopes()->find($this->workflowId);
        if (! $workflow || ! $workflow->isActive()) {
            $this->addError('workflowId', 'Workflow must be active to be scheduled.');

            return;
        }

        $team = auth()->user()->currentTeam;

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

        $project = app(CreateProjectAction::class)->execute(
            userId: auth()->id(),
            title: $this->title,
            type: 'continuous',
            description: $this->description ?: null,
            goal: $workflow->description,
            workflowId: $this->workflowId,
            budgetConfig: $budgetConfig ?: [],
            notificationConfig: [
                'on_failure' => true,
                'on_milestone' => true,
                'on_budget_warning' => true,
                'channels' => ['database'],
            ],
            schedule: [
                'frequency' => $this->frequency,
                'cron_expression' => $this->frequency === 'cron' ? $this->cronExpression : null,
                'timezone' => $this->timezone,
                'overlap_policy' => $this->overlapPolicy,
                'max_consecutive_failures' => $this->maxConsecutiveFailures,
                'run_immediately' => $this->runImmediately,
            ],
            teamId: $team->id,
        );

        // Save delivery config
        if ($deliveryConfig) {
            $project->update(['delivery_config' => $deliveryConfig]);
        }

        // Activate immediately if requested
        if ($this->activateOnSave) {
            $project->update([
                'status' => ProjectStatus::Active,
                'started_at' => now(),
            ]);

            // Trigger first run immediately if requested
            if ($this->runImmediately) {
                app(TriggerProjectRunAction::class)->execute($project->fresh(), 'initial');
            }
        }

        session()->flash('message', 'Scheduled workflow created successfully!');
        $this->redirect(route('projects.show', $project));
    }

    public function render()
    {
        $workflows = Workflow::where('status', WorkflowStatus::Active)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'estimated_cost_credits']);

        $frequencies = ScheduleFrequency::cases();
        $overlapPolicies = OverlapPolicy::cases();

        return view('livewire.workflows.schedule-workflow-form', [
            'workflows' => $workflows,
            'frequencies' => $frequencies,
            'overlapPolicies' => $overlapPolicies,
        ])->layout('layouts.app', ['header' => 'Schedule Workflow']);
    }
}
