<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\ImportSkillFromAgentSkillsAction;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Component;

class ImportSkillForm extends Component
{
    public string $skillMd = '';

    public function import(ImportSkillFromAgentSkillsAction $action)
    {
        Gate::authorize('edit-content');

        $this->validate([
            'skillMd' => 'required|string',
        ]);

        try {
            $skill = $action->execute(
                teamId: auth()->user()->current_team_id,
                skillMd: $this->skillMd,
                createdBy: auth()->id(),
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('skillMd', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('skills.show', ['skill' => $skill->id], navigate: true);
    }

    public function render()
    {
        return view('livewire.skills.import-skill-form');
    }
}
