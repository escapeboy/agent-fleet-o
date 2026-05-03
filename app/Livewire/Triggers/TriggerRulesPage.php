<?php

namespace App\Livewire\Triggers;

use App\Domain\Project\Models\Project;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Actions\DeleteTriggerRuleAction;
use App\Domain\Trigger\Actions\ExecuteTriggerRuleAction;
use App\Domain\Trigger\Actions\UpdateTriggerRuleAction;
use App\Domain\Trigger\Models\TriggerRule;
use Livewire\Component;
use Livewire\WithPagination;

class TriggerRulesPage extends Component
{
    use WithPagination;

    public string $search = '';

    // Edit state
    public ?string $editingRuleId = null;

    public string $editName = '';

    public string $editSourceType = '*';

    public ?string $editProjectId = null;

    public int $editCooldownSeconds = 0;

    public int $editMaxConcurrent = 1;

    /** @var list<string> */
    public array $availableSourceTypes = [
        '*', 'email', 'rss', 'api_polling', 'calendar', 'github_issues',
        'jira', 'linear', 'sentry', 'datadog', 'pagerduty', 'telegram', 'webhook',
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startEdit(string $ruleId): void
    {
        $rule = TriggerRule::findOrFail($ruleId);
        $this->editingRuleId = $rule->id;
        $this->editName = $rule->name;
        $this->editSourceType = $rule->source_type;
        $this->editProjectId = $rule->project_id;
        $this->editCooldownSeconds = $rule->cooldown_seconds ?? 0;
        $this->editMaxConcurrent = $rule->max_concurrent ?? 1;
    }

    public function cancelEdit(): void
    {
        $this->editingRuleId = null;
    }

    public function saveEdit(): void
    {
        $teamId = auth()->user()->current_team_id;

        $this->validate([
            'editName' => 'required|string|max:255',
            'editSourceType' => 'required|string',
            'editProjectId' => "nullable|uuid|exists:projects,id,team_id,{$teamId}",
            'editCooldownSeconds' => 'integer|min:0|max:86400',
            'editMaxConcurrent' => 'integer|min:-1|max:10',
        ]);

        $rule = TriggerRule::findOrFail($this->editingRuleId);

        app(UpdateTriggerRuleAction::class)->execute($rule, [
            'name' => $this->editName,
            'source_type' => $this->editSourceType,
            'project_id' => $this->editProjectId ?: null,
            'cooldown_seconds' => $this->editCooldownSeconds,
            'max_concurrent' => $this->editMaxConcurrent,
        ]);

        $this->editingRuleId = null;
        session()->flash('message', 'Trigger rule updated.');
    }

    public function testTrigger(string $ruleId): void
    {
        $rule = TriggerRule::findOrFail($ruleId);

        // Find the most recent matching signal, or create a test one
        $signal = Signal::where('team_id', $rule->team_id)
            ->when($rule->source_type !== '*', fn ($q) => $q->where('source_type', $rule->source_type))
            ->latest()
            ->first();

        if (! $signal) {
            session()->flash('error', 'No matching signal found to test with. Ingest a signal first.');

            return;
        }

        try {
            $run = app(ExecuteTriggerRuleAction::class)->execute($rule, $signal);

            if ($run) {
                session()->flash('message', "Trigger fired! Project run {$run->id} created.");
            } else {
                session()->flash('error', 'Trigger skipped (cooldown active, max concurrent reached, or no project linked).');
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Trigger failed: '.$e->getMessage());
        }
    }

    public function toggleStatus(string $ruleId): void
    {
        $rule = TriggerRule::findOrFail($ruleId);

        $newStatus = $rule->status->isActive() ? 'paused' : 'active';
        app(UpdateTriggerRuleAction::class)->execute($rule, ['status' => $newStatus]);

        session()->flash('message', 'Rule '.($newStatus === 'active' ? 'activated' : 'paused').'.');
    }

    public function delete(string $ruleId): void
    {
        $rule = TriggerRule::findOrFail($ruleId);
        app(DeleteTriggerRuleAction::class)->execute($rule);

        session()->flash('message', 'Trigger rule deleted.');
    }

    public function render()
    {
        $query = TriggerRule::with('project')
            ->orderBy('created_at', 'desc');

        if ($this->search) {
            $query->where('name', 'ilike', "%{$this->search}%");
        }

        return view('livewire.triggers.trigger-rules-page', [
            'rules' => $query->paginate(20),
            'projects' => Project::orderBy('title')->get(['id', 'title']),
        ])->layout('layouts.app', ['header' => 'Trigger Rules']);
    }
}
