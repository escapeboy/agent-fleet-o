<?php

namespace App\Livewire\Signals;

use App\Domain\Agent\Models\Agent;
use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Actions\DelegateBugReportToAgentAction;
use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SignalStatusTransitionMap;
use Livewire\Component;

class BugReportDetailPage extends Component
{
    public Signal $signal;

    public string $commentText = '';

    public string $delegateAgentId = '';

    public function mount(Signal $signal): void
    {
        abort_if($signal->source_type !== 'bug_report', 404);
        $this->signal = $signal;
    }

    public function updateStatus(string $status): void
    {
        try {
            $newStatus = SignalStatus::from($status);
            app(UpdateSignalStatusAction::class)->execute(
                signal: $this->signal,
                newStatus: $newStatus,
                actor: auth()->user(),
            );
            $this->signal->refresh();
        } catch (InvalidSignalTransitionException $e) {
            $this->addError('status', $e->getMessage());
        }
    }

    public function addComment(): void
    {
        $this->validate(['commentText' => ['required', 'string', 'min:1', 'max:5000']]);

        app(AddSignalCommentAction::class)->execute(
            signal: $this->signal,
            body: $this->commentText,
            authorType: 'human',
            userId: auth()->id(),
        );

        $this->commentText = '';
        $this->signal->load('comments.user');
    }

    public function delegateToAgent(): void
    {
        $this->validate(['delegateAgentId' => ['required', 'uuid']]);

        $agent = Agent::find($this->delegateAgentId);
        abort_if(! $agent, 404);

        app(DelegateBugReportToAgentAction::class)->execute(
            signal: $this->signal,
            actor: auth()->user(),
            agentId: $agent->id,
        );

        $this->signal->refresh();
        $this->delegateAgentId = '';
    }

    public function render(): \Illuminate\View\View
    {
        $transitionMap = app(SignalStatusTransitionMap::class);
        $allowedTransitions = $transitionMap->allowedTransitionsFrom(
            $this->signal->status ?? SignalStatus::Received
        );

        $agents = Agent::query()->orderBy('name')->get(['id', 'name']);

        $this->signal->load('comments.user');

        return view('livewire.signals.bug-report-detail', [
            'allowedTransitions' => $allowedTransitions,
            'agents' => $agents,
            'screenshotUrl' => $this->signal->getFirstMediaUrl('bug_report_files'),
        ]);
    }
}
