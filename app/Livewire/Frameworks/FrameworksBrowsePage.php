<?php

declare(strict_types=1);

namespace App\Livewire\Frameworks;

use App\Domain\Skill\Enums\Framework;
use App\Domain\Skill\Enums\FrameworkCategory;
use App\Domain\Skill\Models\Skill;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;

final class FrameworksBrowsePage extends Component
{
    #[Url(as: 'category')]
    public string $category = '';

    public function render()
    {
        $teamId = Auth::user()->current_team_id;
        $selectedCategory = $this->category !== '' ? FrameworkCategory::tryFrom($this->category) : null;

        $cacheKey = "framework_counts:{$teamId}";
        $counts = Cache::remember($cacheKey, 300, function () use ($teamId) {
            return Skill::query()
                ->where('team_id', $teamId)
                ->whereNotNull('framework')
                ->selectRaw('framework, count(*) as total')
                ->groupBy('framework')
                ->pluck('total', 'framework')
                ->toArray();
        });

        $frameworks = collect(Framework::cases())
            ->when(
                $selectedCategory,
                fn ($collection) => $collection->filter(fn (Framework $f) => $f->category() === $selectedCategory),
            )
            ->map(fn (Framework $f) => [
                'framework' => $f,
                'skill_count' => (int) ($counts[$f->value] ?? 0),
            ])
            ->values();

        return view('livewire.frameworks.browse', [
            'frameworks' => $frameworks,
            'categories' => FrameworkCategory::cases(),
            'selectedCategory' => $selectedCategory,
        ])->layout('layouts.app', ['header' => 'Frameworks']);
    }
}
