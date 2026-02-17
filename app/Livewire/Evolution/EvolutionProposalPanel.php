<?php

namespace App\Livewire\Evolution;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Actions\AnalyzeExecutionForEvolutionAction;
use App\Domain\Evolution\Actions\ApplyEvolutionProposalAction;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use Livewire\Component;

class EvolutionProposalPanel extends Component
{
    public Agent $agent;

    public ?string $error = null;

    public ?string $success = null;

    public bool $analyzing = false;

    public function analyze(): void
    {
        $this->error = null;
        $this->success = null;

        try {
            $latestExecution = $this->agent->executions()->latest()->first();

            app(AnalyzeExecutionForEvolutionAction::class)->execute(
                $this->agent,
                $latestExecution,
            );

            $this->success = 'Analysis complete. New evolution proposal created.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function approve(string $proposalId): void
    {
        $proposal = EvolutionProposal::findOrFail($proposalId);
        $proposal->update([
            'status' => EvolutionProposalStatus::Approved,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->success = 'Proposal approved. You can now apply it.';
    }

    public function apply(string $proposalId): void
    {
        $this->error = null;

        try {
            $proposal = EvolutionProposal::findOrFail($proposalId);

            app(ApplyEvolutionProposalAction::class)->execute(
                $proposal,
                auth()->id(),
            );

            $this->agent->refresh();
            $this->success = 'Evolution applied to agent successfully.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function reject(string $proposalId): void
    {
        $proposal = EvolutionProposal::findOrFail($proposalId);
        $proposal->update([
            'status' => EvolutionProposalStatus::Rejected,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->success = 'Proposal rejected.';
    }

    public function render()
    {
        $proposals = EvolutionProposal::where('agent_id', $this->agent->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('livewire.evolution.evolution-proposal-panel', [
            'proposals' => $proposals,
        ]);
    }
}
