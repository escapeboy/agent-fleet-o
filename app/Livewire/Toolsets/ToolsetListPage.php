<?php

namespace App\Livewire\Toolsets;

use App\Domain\Tool\Models\Toolset;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ToolsetListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Toolset::query()->withCount('agents');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('description', 'ilike', "%{$this->search}%");
            });
        }

        $query->orderBy('name');

        return view('livewire.toolsets.toolset-list-page', [
            'toolsets' => $query->paginate(20),
        ])->layout('layouts.app', ['header' => 'Toolsets']);
    }
}
