<?php

namespace App\Livewire\Broadcast;

use App\Domain\Broadcast\Actions\ApproveBroadcast;
use App\Domain\Broadcast\Actions\CancelBroadcast;
use App\Domain\Broadcast\Actions\RequestBroadcastApproval;
use App\Domain\Broadcast\Models\Broadcast;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class BroadcastDetailPage extends Component
{
    public Broadcast $broadcast;

    public function mount(Broadcast $broadcast): void
    {
        $this->broadcast = $broadcast;
    }

    public function requestApproval(): void
    {
        Gate::authorize('edit-content');

        try {
            app(RequestBroadcastApproval::class)->execute($this->broadcast, (string) auth()->id());
            session()->flash('message', 'Broadcast submitted for approval.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->broadcast->refresh();
    }

    public function approve(): void
    {
        Gate::authorize('manage-team');

        try {
            app(ApproveBroadcast::class)->execute($this->broadcast, (string) auth()->id());
            session()->flash('message', 'Broadcast approved — delivery has started.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->broadcast->refresh();
    }

    public function cancel(): void
    {
        Gate::authorize('edit-content');

        try {
            app(CancelBroadcast::class)->execute($this->broadcast);
            session()->flash('message', 'Broadcast cancelled.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->broadcast->refresh();
    }

    public function render()
    {
        return view('livewire.broadcast.broadcast-detail-page', [
            'recipients' => $this->broadcast->recipients()->with('contactIdentity')->latest()->get(),
        ])->layout('layouts.app', ['header' => 'Broadcast']);
    }
}
