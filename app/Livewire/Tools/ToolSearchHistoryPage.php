<?php

namespace App\Livewire\Tools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Models\ToolSearchLog;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ToolSearchHistoryPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $agentFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAgentFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = ToolSearchLog::query()->with('agent:id,name');

        if ($this->search !== '') {
            // Postgres uses ilike (case-insensitive); SQLite (tests) falls back to LIKE LOWER().
            $driver = \DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                $query->where('query', 'ilike', '%'.$this->search.'%');
            } else {
                $needle = strtolower($this->search);
                $query->whereRaw('LOWER(query) LIKE ?', ['%'.$needle.'%']);
            }
        }

        if ($this->agentFilter !== '') {
            $query->where('agent_id', $this->agentFilter);
        }

        $query->orderBy('created_at', 'desc');

        $logs = $query->paginate(25);

        $agents = Agent::orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn ($a) => ! empty($a->config['use_tool_search'] ?? false))
            ->values();

        return view('livewire.tools.tool-search-history-page', [
            'logs' => $logs,
            'agents' => $agents,
        ])->layout('layouts.app', ['header' => 'Tool Search History']);
    }
}
