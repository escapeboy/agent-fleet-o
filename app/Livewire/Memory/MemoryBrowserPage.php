<?php

namespace App\Livewire\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class MemoryBrowserPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $agentFilter = '';

    #[Url]
    public string $projectFilter = '';

    #[Url]
    public string $sourceTypeFilter = '';

    /** Filter by memory tier. Empty string means all tiers. */
    #[Url]
    public string $tierFilter = '';

    /** Filter by tag. Empty string means all tags. */
    #[Url]
    public string $tagFilter = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public ?string $expandedId = null;

    private const ALLOWED_SORT_FIELDS = ['created_at', 'updated_at', 'tier', 'confidence', 'importance'];

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::ALLOWED_SORT_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAgentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedProjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTierFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTagFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Replace all tags on a memory. Accepts a comma-separated string.
     */
    public function updateTags(string $memoryId, string $tagsString): void
    {
        Gate::authorize('edit-content');

        $tags = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $tagsString)),
        )));

        // Validate tag format: only lowercase alphanumeric, colons, hyphens, underscores
        foreach ($tags as $tag) {
            if (! preg_match('/^[a-z0-9:_-]{1,50}$/', $tag)) {
                session()->flash('error', "Invalid tag format: \"{$tag}\". Use lowercase letters, numbers, colons, hyphens, underscores (max 50 chars).");

                return;
            }
        }

        Memory::where('id', $memoryId)->update(['tags' => $tags]);

        session()->flash('message', 'Tags updated.');
    }

    public function toggleExpand(string $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function deleteMemory(string $id): void
    {
        Gate::authorize('edit-content');

        Memory::where('id', $id)->delete();

        if ($this->expandedId === $id) {
            $this->expandedId = null;
        }

        session()->flash('message', 'Memory deleted.');
    }

    /**
     * Promote a memory to the given target tier.
     * Requires the edit-content gate.
     */
    public function promoteTier(string $memoryId, string $targetTier): void
    {
        Gate::authorize('edit-content');

        $tier = MemoryTier::tryFrom($targetTier);
        if (! $tier) {
            session()->flash('error', 'Invalid target tier.');

            return;
        }

        Memory::where('id', $memoryId)->update(['tier' => $tier->value]);

        session()->flash('message', "Memory promoted to {$tier->value}.");
    }

    public function render(): View
    {
        $query = Memory::query()->with(['agent', 'project']);

        if ($this->search) {
            $query->where('content', 'ilike', "%{$this->search}%");
        }

        if ($this->agentFilter) {
            $query->where('agent_id', $this->agentFilter);
        }

        if ($this->projectFilter) {
            $query->where('project_id', $this->projectFilter);
        }

        if ($this->sourceTypeFilter) {
            $query->where('source_type', $this->sourceTypeFilter);
        }

        if ($this->tierFilter) {
            $query->where('tier', $this->tierFilter);
        }

        if ($this->tagFilter) {
            if (config('database.default') === 'pgsql') {
                $query->whereRaw('tags @> ?', [json_encode([$this->tagFilter])]);
            } else {
                $query->whereRaw(
                    'EXISTS (SELECT 1 FROM json_each(tags) WHERE json_each.value = ?)',
                    [$this->tagFilter],
                );
            }
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        // Count unreviewed proposed memories for the badge
        $proposalCount = Memory::query()
            ->where('tier', MemoryTier::Proposed->value)
            ->count();

        // Collect distinct tags across all memories for the filter dropdown
        $availableTags = collect();
        if (config('database.default') === 'pgsql') {
            $availableTags = collect(DB::select(
                "SELECT DISTINCT jsonb_array_elements_text(tags) AS tag FROM memories WHERE tags IS NOT NULL AND tags != '[]'::jsonb ORDER BY tag",
            ))->pluck('tag');
        }

        return view('livewire.memory.memory-browser-page', [
            'memories' => $query->paginate(30),
            'agents' => Agent::orderBy('name')->pluck('name', 'id'),
            'projects' => Project::orderBy('title')->pluck('title', 'id'),
            'sourceTypes' => Memory::distinct()->pluck('source_type')->sort()->values(),
            'tiers' => MemoryTier::cases(),
            'proposalCount' => $proposalCount,
            'availableTags' => $availableTags,
        ])->layout('layouts.app', ['header' => 'Memory Browser']);
    }
}
