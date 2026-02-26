<?php

namespace App\Livewire\Signals;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Shared\Services\ContactResolver;
use Livewire\Component;

class ContactDetailPage extends Component
{
    public ContactIdentity $contact;

    /** @var string UUID of another ContactIdentity to merge into this one */
    public string $mergeTargetId = '';

    public bool $showMergeModal = false;

    public function toggleMergeModal(): void
    {
        $this->showMergeModal = ! $this->showMergeModal;
        $this->mergeTargetId = '';
    }

    public function merge(): void
    {
        $this->validate(['mergeTargetId' => 'required|uuid']);

        $source = ContactIdentity::findOrFail($this->mergeTargetId);

        app(ContactResolver::class)->merge($source, $this->contact);

        $this->contact->refresh();
        $this->showMergeModal = false;
        $this->dispatch('toast', message: 'Contacts merged successfully.', type: 'success');
    }

    public function unlinkChannel(string $channelId): void
    {
        $channel = $this->contact->channels()->findOrFail($channelId);
        $channel->delete();

        $this->contact->refresh();
        $this->dispatch('toast', message: 'Channel unlinked.', type: 'success');
    }

    public function render()
    {
        $this->contact->load(['channels']);

        $signals = \App\Domain\Signal\Models\Signal::withoutGlobalScopes()
            ->where('contact_identity_id', $this->contact->id)
            ->latest('received_at')
            ->limit(20)
            ->get();

        return view('livewire.signals.contact-detail-page', [
            'signals' => $signals,
        ])->layout('layouts.app', ['header' => 'Contact: '.($this->contact->display_name ?? $this->contact->id)]);
    }
}
