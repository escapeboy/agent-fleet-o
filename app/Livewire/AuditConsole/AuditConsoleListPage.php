<?php

namespace App\Livewire\AuditConsole;

use FleetQ\BorunaAudit\Models\AuditableDecision;
use FleetQ\BorunaAudit\Services\BundleVerifier;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class AuditConsoleListPage extends Component
{
    #[Url]
    public string $workflow = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public ?string $cursor = null;

    public function mount(): void
    {
        // audit-console.view: accessible to admin and owner roles (manage-team gate).
        // Viewers are excluded — audit records are compliance data, not general content.
        abort_unless(auth()->check() && Gate::allows('manage-team'), 403);
    }

    public function verify(string $decisionId): void
    {
        $teamId = auth()->user()->currentTeam->id;

        $decision = AuditableDecision::where('id', $decisionId)
            ->where('team_id', $teamId)
            ->firstOrFail();

        $verifier = app(BundleVerifier::class);
        $result = $verifier->verify($decision, $teamId);

        if ($result->passed) {
            session()->flash('success', 'Bundle verification passed.');
        } else {
            session()->flash('error', 'Bundle verification failed: '.($result->errorMessage ?? 'Unknown error'));
        }
    }

    public function render()
    {
        $teamId = auth()->user()->currentTeam->id;

        $query = AuditableDecision::where('team_id', $teamId)
            ->orderByDesc('created_at');

        if ($this->workflow !== '') {
            $query->where('workflow_name', $this->workflow);
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $decisions = $query->cursorPaginate(25);

        $workflows = AuditableDecision::where('team_id', $teamId)
            ->distinct()
            ->pluck('workflow_name');

        return view('livewire.audit-console.list', compact('decisions', 'workflows'))
            ->title('Audit Console');
    }
}
