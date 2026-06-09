<?php

namespace App\Livewire\Broadcast;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\Audience;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Broadcast\Actions\CreateBroadcast;
use App\Domain\Broadcast\Services\BroadcastBudgetGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class CreateBroadcastForm extends Component
{
    public string $audienceId = '';

    public string $name = '';

    public string $subject = '';

    public string $body = '';

    public function create(): void
    {
        Gate::authorize('edit-content');

        $teamId = auth()->user()->currentTeam->id;

        $this->validate([
            'audienceId' => ['required', 'uuid',
                Rule::exists('audiences', 'id')->where('team_id', $teamId)],
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $audience = Audience::query()->findOrFail($this->audienceId);

        $broadcast = app(CreateBroadcast::class)->execute(
            audience: $audience,
            name: $this->name,
            subject: $this->subject,
            body: $this->body,
        );

        session()->flash('message', 'Broadcast created as a draft. Submit it for approval to send.');
        $this->redirect(route('broadcasts.show', $broadcast));
    }

    public function render()
    {
        $estimate = null;

        if ($this->audienceId !== '') {
            $recipientCount = AudienceMember::query()
                ->where('audience_id', $this->audienceId)
                ->where('status', AudienceMemberStatus::Subscribed->value)
                ->count();

            $estimate = [
                'recipients' => $recipientCount,
                'max_recipients' => BroadcastBudgetGuard::MAX_RECIPIENTS,
                'estimated_credits' => $recipientCount,
            ];
        }

        return view('livewire.broadcast.create-broadcast-form', [
            'audiences' => Audience::query()->orderBy('name')->get(['id', 'name']),
            'estimate' => $estimate,
        ])->layout('layouts.app', ['header' => 'New Broadcast']);
    }
}
