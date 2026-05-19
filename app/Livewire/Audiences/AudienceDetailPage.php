<?php

namespace App\Livewire\Audiences;

use App\Domain\Audience\Actions\AddAudienceMember;
use App\Domain\Audience\Actions\UnsubscribeContact;
use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Actions\CreateBroadcast;
use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class AudienceDetailPage extends Component
{
    public Audience $audience;

    public string $memberEmail = '';

    public string $broadcastName = '';

    public string $broadcastSubject = '';

    public string $broadcastBody = '';

    public function mount(Audience $audience): void
    {
        $this->audience = $audience;
    }

    public function addMember(): void
    {
        Gate::authorize('edit-content');

        $this->validate(['memberEmail' => 'required|email']);

        $contact = ContactIdentity::firstOrCreate([
            'team_id' => $this->audience->team_id,
            'email' => $this->memberEmail,
        ]);

        app(AddAudienceMember::class)->execute($this->audience, $contact);

        $this->reset('memberEmail');
        session()->flash('message', 'Member added.');
    }

    public function unsubscribe(string $contactId): void
    {
        Gate::authorize('edit-content');

        $contact = ContactIdentity::findOrFail($contactId);
        app(UnsubscribeContact::class)->execute(
            teamId: $this->audience->team_id,
            contact: $contact,
            audienceId: $this->audience->id,
            reason: 'manual',
        );
    }

    public function createBroadcast()
    {
        Gate::authorize('edit-content');

        $this->validate([
            'broadcastName' => 'required|string|max:255',
            'broadcastSubject' => 'required|string|max:255',
            'broadcastBody' => 'required|string',
        ]);

        $broadcast = app(CreateBroadcast::class)->execute(
            audience: $this->audience,
            name: $this->broadcastName,
            subject: $this->broadcastSubject,
            body: $this->broadcastBody,
        );

        return $this->redirect(route('broadcasts.show', $broadcast), navigate: true);
    }

    public function render()
    {
        return view('livewire.audiences.audience-detail-page', [
            'members' => $this->audience->members()->with('contactIdentity')->latest()->get(),
            'broadcasts' => $this->audience->broadcasts()->latest()->get(),
        ])->layout('layouts.app', ['header' => 'Audience']);
    }
}
