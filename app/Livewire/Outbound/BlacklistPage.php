<?php

namespace App\Livewire\Outbound;

use App\Models\Blacklist;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class BlacklistPage extends Component
{
    use WithPagination;

    public string $type = 'email';

    public string $value = '';

    public string $reason = '';

    public function add(): void
    {
        Gate::authorize('edit-content');

        $validated = $this->validate([
            'type' => 'required|string|in:email,domain,company,keyword',
            'value' => 'required|string|max:255',
            'reason' => 'nullable|string|max:255',
        ]);

        Blacklist::create([
            'type' => $validated['type'],
            'value' => strtolower(trim($validated['value'])),
            'reason' => $validated['reason'] ?: null,
            'added_by' => auth()->id(),
        ]);

        $this->reset(['value', 'reason']);
        session()->flash('message', 'Blacklist entry added.');
    }

    public function remove(string $id): void
    {
        Gate::authorize('edit-content');

        $entry = Blacklist::findOrFail($id);
        $entry->delete();

        session()->flash('message', 'Blacklist entry removed.');
    }

    public function render()
    {
        return view('livewire.outbound.blacklist-page', [
            'entries' => Blacklist::query()
                ->orderBy('type')
                ->orderBy('value')
                ->paginate(25),
        ])->layout('layouts.app', ['header' => 'Outbound Blacklist']);
    }
}
