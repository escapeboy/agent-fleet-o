<?php

namespace App\Livewire\Signals;

use App\Domain\Signal\Enums\ConnectorBindingStatus;
use App\Domain\Signal\Models\ConnectorBinding;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ConnectorBindingsPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $channelFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    public function approve(string $bindingId): void
    {
        $binding = ConnectorBinding::findOrFail($bindingId);

        $binding->update([
            'status' => ConnectorBindingStatus::Approved,
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        $this->dispatch('toast', message: 'Sender approved.', type: 'success');
    }

    public function block(string $bindingId): void
    {
        $binding = ConnectorBinding::findOrFail($bindingId);

        $binding->update([
            'status' => ConnectorBindingStatus::Blocked,
        ]);

        $this->dispatch('toast', message: 'Sender blocked.', type: 'success');
    }

    public function delete(string $bindingId): void
    {
        ConnectorBinding::findOrFail($bindingId)->delete();

        $this->dispatch('toast', message: 'Binding deleted.', type: 'success');
    }

    public function render()
    {
        $query = ConnectorBinding::query()->orderBy('created_at', 'desc');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('external_id', 'ilike', "%{$this->search}%")
                    ->orWhere('external_name', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->channelFilter) {
            $query->where('channel', $this->channelFilter);
        }

        $channels = ConnectorBinding::query()
            ->distinct()
            ->pluck('channel')
            ->sort()
            ->values();

        return view('livewire.signals.connector-bindings-page', [
            'bindings' => $query->paginate(25),
            'channels' => $channels,
        ])->layout('layouts.app', ['header' => 'Connector Bindings']);
    }
}
