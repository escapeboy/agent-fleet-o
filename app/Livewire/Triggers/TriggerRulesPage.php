<?php

namespace App\Livewire\Triggers;

use App\Domain\Trigger\Actions\DeleteTriggerRuleAction;
use App\Domain\Trigger\Actions\UpdateTriggerRuleAction;
use App\Domain\Trigger\Models\TriggerRule;
use Livewire\Component;
use Livewire\WithPagination;

class TriggerRulesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function toggleStatus(string $ruleId): void
    {
        $rule = TriggerRule::findOrFail($ruleId);

        $newStatus = $rule->status->isActive() ? 'paused' : 'active';
        app(UpdateTriggerRuleAction::class)->execute($rule, ['status' => $newStatus]);

        $this->dispatch('toast', message: 'Rule '.($newStatus === 'active' ? 'activated' : 'paused').'.', type: 'success');
    }

    public function delete(string $ruleId): void
    {
        $rule = TriggerRule::findOrFail($ruleId);
        app(DeleteTriggerRuleAction::class)->execute($rule);

        $this->dispatch('toast', message: 'Trigger rule deleted.', type: 'success');
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
        ])->layout('layouts.app', ['header' => 'Trigger Rules']);
    }
}
