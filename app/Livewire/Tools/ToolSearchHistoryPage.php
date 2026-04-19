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

        // UUID guard on the filter param — user-controlled via #[Url]. A non-UUID
        // would produce a PostgreSQL 22P02 and a 500; silently reset so the page
        // still renders with no filter applied.
        if ($this->agentFilter !== '') {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this->agentFilter)) {
                $query->where('agent_id', $this->agentFilter);
            } else {
                $this->agentFilter = '';
            }
        }

        $query->orderBy('created_at', 'desc');

        $logs = $query->paginate(25);

        $agents = Agent::orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn ($a) => ! empty($a->config['use_tool_search'] ?? false))
            ->values();

        $stats = $this->aggregateStats();

        return view('livewire.tools.tool-search-history-page', [
            'logs' => $logs,
            'agents' => $agents,
            'stats' => $stats,
        ])->layout('layouts.app', ['header' => 'Tool Search History']);
    }

    /**
     * Aggregate observability stats across all tool_search_logs for the current team.
     *
     * @return array{total: int, avg_matched: float, avg_pool: float, zero_match_rate: float, top_slugs: array<int, array{slug: string, count: int}>}
     */
    private function aggregateStats(): array
    {
        $base = ToolSearchLog::query();

        $total = (clone $base)->count();
        if ($total === 0) {
            return [
                'total' => 0,
                'avg_matched' => 0.0,
                'avg_pool' => 0.0,
                'zero_match_rate' => 0.0,
                'top_slugs' => [],
            ];
        }

        $avgMatched = (float) (clone $base)->avg('matched_count');
        $avgPool = (float) (clone $base)->avg('pool_size');
        $zeroMatches = (clone $base)->where('matched_count', 0)->count();

        // Count slug frequencies by unpacking the last 500 rows in-memory.
        // Postgres-native jsonb_array_elements would be cleaner but the hybrid
        // pgsql/sqlite test config rules it out. 500 rows is a sensible sample.
        $slugCounts = [];
        (clone $base)->orderBy('created_at', 'desc')->take(500)->get(['matched_slugs'])
            ->each(function ($log) use (&$slugCounts) {
                foreach (($log->matched_slugs ?? []) as $slug) {
                    $slugCounts[$slug] = ($slugCounts[$slug] ?? 0) + 1;
                }
            });

        arsort($slugCounts);
        $topSlugs = array_slice(
            array_map(fn ($count, $slug) => ['slug' => $slug, 'count' => $count],
                array_values($slugCounts), array_keys($slugCounts)),
            0, 10,
        );

        return [
            'total' => $total,
            'avg_matched' => round($avgMatched, 2),
            'avg_pool' => round($avgPool, 2),
            'zero_match_rate' => round($zeroMatches / $total, 3),
            'top_slugs' => $topSlugs,
        ];
    }
}
