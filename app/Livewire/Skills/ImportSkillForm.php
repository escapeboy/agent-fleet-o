<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\ImportSkillFromAgentSkillsAction;
use App\Domain\Skill\Actions\ImportSkillFromGitHubAction;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Component;

class ImportSkillForm extends Component
{
    public string $skillMd = '';

    public string $githubSource = '';

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

    public function importFromGitHub(ImportSkillFromGitHubAction $action)
    {
        Gate::authorize('edit-content');

        $this->validate([
            'githubSource' => 'required|string',
        ]);

        try {
            $result = $action->execute(
                teamId: auth()->user()->current_team_id,
                source: $this->githubSource,
                createdBy: auth()->id(),
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('githubSource', $e->getMessage());

            return null;
        }

        $imported = $result['imported'];

        if (count($imported) === 1 && $result['failed'] === []) {
            return $this->redirectRoute('skills.show', ['skill' => $imported[0]->id], navigate: true);
        }

        session()->flash('status', sprintf(
            'Imported %d skill(s) from %s%s.',
            count($imported),
            $this->githubSource,
            $result['failed'] !== [] ? ', '.count($result['failed']).' skipped' : '',
        ));

        return $this->redirectRoute('skills.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.skills.import-skill-form');
    }
}
