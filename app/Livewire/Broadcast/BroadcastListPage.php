<?php

namespace App\Livewire\Broadcast;

use App\Domain\Broadcast\Models\Broadcast;
use Livewire\Component;
use Livewire\WithPagination;

class BroadcastListPage extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.broadcast.broadcast-list-page', [
            'broadcasts' => Broadcast::query()
                ->with('audience:id,name')
                ->latest()
                ->paginate(20),
        ])->layout('layouts.app', ['header' => 'Broadcasts']);
    }
}
