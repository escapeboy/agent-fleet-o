<?php

namespace App\Livewire\Agents;

use Livewire\Attributes\Url;
use Livewire\Component;

class AgentTemplateGalleryPage extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $categoryFilter = '';

    public function updatedSearch(): void
    {
        // Reactivity handled by Livewire
    }

    public function updatedCategoryFilter(): void
    {
        // Reactivity handled by Livewire
    }

    public function useTemplate(string $slug): void
    {
        $this->redirect(route('agents.create', ['template' => $slug]));
    }

    public function render()
    {
        $templates = collect(config('agent-templates', []));

        if ($this->search) {
            $search = mb_strtolower($this->search);
            $templates = $templates->filter(fn (array $t) => str_contains(mb_strtolower($t['name']), $search)
                || str_contains(mb_strtolower($t['role'] ?? ''), $search)
                || str_contains(mb_strtolower($t['goal'] ?? ''), $search)
                || collect($t['capabilities'] ?? [])->contains(fn ($c) => str_contains(mb_strtolower($c), $search))
            );
        }

        if ($this->categoryFilter) {
            $templates = $templates->where('category', $this->categoryFilter);
        }

        $categories = collect(config('agent-templates', []))
            ->pluck('category')
            ->unique()
            ->sort()
            ->values();

        return view('livewire.agents.agent-template-gallery-page', [
            'templates' => $templates,
            'categories' => $categories,
        ])->layout('layouts.app', ['header' => 'Agent Templates']);
    }
}
