<?php

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\UnifiedTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnifiedTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function experimentWithTransition(): Experiment
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);
        ExperimentStateTransition::create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'from_state' => 'draft',
            'to_state' => 'scoring',
            'created_at' => now(),
        ]);

        return $experiment;
    }

    public function test_renders_timeline_with_an_entry(): void
    {
        Livewire::test(UnifiedTimeline::class, ['experiment' => $this->experimentWithTransition()])
            ->assertOk()
            ->assertSee('scoring')
            ->assertSee('Transitions');
    }

    public function test_kind_filter_hides_non_matching_entries(): void
    {
        Livewire::test(UnifiedTimeline::class, ['experiment' => $this->experimentWithTransition()])
            ->set('kindFilter', 'ai_run')
            ->assertDontSee('State → scoring')
            ->assertSee('No activity recorded');
    }
}
