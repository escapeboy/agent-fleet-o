<?php

namespace App\Livewire\Signals;

use App\Domain\Shared\Models\ContactIdentity;
use Livewire\Component;
use Livewire\WithPagination;

class ContactsPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $channelFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = ContactIdentity::query()
            ->withCount('channels')
            ->with('channels')
            ->orderByDesc('updated_at');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('display_name', 'ilike', "%{$this->search}%")
                    ->orWhere('email', 'ilike', "%{$this->search}%")
                    ->orWhere('phone', 'ilike', "%{$this->search}%")
                    ->orWhereHas('channels', fn ($cq) => $cq->where('external_id', 'ilike', "%{$this->search}%")
                        ->orWhere('external_username', 'ilike', "%{$this->search}%"));
            });
        }

        if ($this->channelFilter) {
            $query->whereHas('channels', fn ($cq) => $cq->where('channel', $this->channelFilter));
        }

        $channels = \App\Domain\Shared\Models\ContactChannel::query()
            ->distinct()
            ->pluck('channel')
            ->sort()
            ->values();

        return view('livewire.signals.contacts-page', [
            'contacts' => $query->paginate(25),
            'channels' => $channels,
        ])->layout('layouts.app', ['header' => 'Contacts']);
    }
}
