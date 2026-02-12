<?php

namespace App\Livewire\Crews;

use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use Livewire\Component;

class CrewExecutionPage extends Component
{
    public Crew $crew;

    public string $goal = '';

    protected function rules(): array
    {
        return [
            'goal' => 'required|min:10|max:5000',
        ];
    }

    public function mount(Crew $crew): void
    {
        $this->crew = $crew;
    }

    public function execute(ExecuteCrewAction $action): void
    {
        $this->validate();

        if ($this->crew->status !== CrewStatus::Active) {
            $this->addError('goal', 'Crew must be active to execute. Please activate it first.');

            return;
        }

        try {
            $execution = $action->execute(
                crew: $this->crew,
                goal: $this->goal,
                teamId: auth()->user()->currentTeam?->id ?? $this->crew->team_id,
            );

            session()->flash('message', 'Crew execution started.');
            $this->redirect(route('crews.show', $this->crew));
        } catch (\InvalidArgumentException $e) {
            $this->addError('goal', $e->getMessage());
        }
    }

    public function render()
    {
        $members = $this->crew->members()->with('agent')->orderBy('sort_order')->get();
        $coordinator = $this->crew->coordinator;
        $qaAgent = $this->crew->qaAgent;

        return view('livewire.crews.crew-execution-page', [
            'members' => $members,
            'coordinator' => $coordinator,
            'qaAgent' => $qaAgent,
        ])->layout('layouts.app', ['header' => "Execute: {$this->crew->name}"]);
    }
}
