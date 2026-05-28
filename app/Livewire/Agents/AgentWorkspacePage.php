<?php

namespace App\Livewire\Agents;

use App\Domain\Agent\Models\Agent;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unified agent workspace — Draft / Test / Deploy / Script in one surface.
 * Borrowed from Salesforce Agentforce Builder's single-workspace UX (2026-05-28 sprint).
 *
 * Wraps but does not replace AgentDetailPage. Detail page remains the rich
 * editor; the workspace is the consolidated "everything about this agent
 * in one place" view.
 */
#[Layout('layouts.app')]
#[Title('Agent workspace')]
class AgentWorkspacePage extends Component
{
    public Agent $agent;

    #[Url(as: 'tab')]
    public string $activeTab = 'draft';

    public function mount(Agent $agent): void
    {
        // Route-model binding + TeamScope already filter to the caller's team.
        // Cross-team agent IDs return a 404 from binding before reaching mount,
        // matching AgentDetailPage's pattern.
        $this->agent = $agent;
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['draft', 'test', 'deploy', 'script'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    /**
     * Build the read-only "what the LLM actually sees" preview shown on the Script tab.
     * Lazily computed — only resolved when the script tab is active to keep the rest
     * of the page fast.
     *
     * @return array{system_prompt: string, skills: array<int, array{name: string, type: string}>, tools: array<int, array{name: string, type: string}>, provider: string|null, model: string|null}
     */
    public function getScriptProperty(): array
    {
        $agent = $this->agent->loadMissing(['skills', 'tools']);

        return [
            'system_prompt' => $this->composeSystemPrompt($agent),
            'skills' => $agent->skills->map(fn (Skill $s) => [
                'name' => (string) $s->name,
                'type' => $s->type->value,
            ])->all(),
            'tools' => $agent->tools->map(fn (Tool $t) => [
                'name' => (string) $t->name,
                'type' => $t->type->value,
            ])->all(),
            'provider' => $agent->provider,
            'model' => $agent->model,
        ];
    }

    private function composeSystemPrompt(Agent $agent): string
    {
        $parts = [];

        if ($agent->role) {
            $parts[] = "## Role\n".$agent->role;
        }

        if ($agent->goal) {
            $parts[] = "## Goal\n".$agent->goal;
        }

        if ($agent->backstory) {
            $parts[] = "## Backstory\n".$agent->backstory;
        }

        return implode("\n\n", $parts) ?: '(no role/goal/backstory configured)';
    }

    public function render()
    {
        return view('livewire.agents.agent-workspace-page');
    }
}
