<?php

namespace Tests\Feature\Security;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\ExperimentDetailPage;
use App\Livewire\Memory\MemoryBrowserPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Values rendered inside Alpine / Livewire JS expressions must go through
 *
 * @js(). HTML escaping alone is not enough: the browser decodes &#039; back
 * to a quote before the expression runs.
 */
class JsContextEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = "x', y: alert(document.domain), z: '";

    public function test_public_marketplace_query_is_not_reflected_into_x_data(): void
    {
        $this->withoutVite();

        // `type` is reflected the same way as `search` (search needs Postgres ILIKE).
        $html = $this->get('/marketplace?type='.urlencode(self::PAYLOAD))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/x-data="(\{ search: [^"]*\})"/', $html, $m));
        $this->assertStringContainsString("type: 'x\\u0027, y: alert(document.domain), z: \\u0027',", $m[1]);
    }

    public function test_memory_tags_cannot_break_out_of_x_data(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team, ['role' => 'owner']);
        $memory = Memory::create([
            'team_id' => $team->id,
            'agent_id' => Agent::factory()->create(['team_id' => $team->id])->id,
            'content' => 'Tagged memory',
            'source_type' => 'test',
            'confidence' => 0.9,
            'importance' => 0.5,
            'tags' => [self::PAYLOAD],
        ]);

        $html = Livewire::actingAs($user)
            ->test(MemoryBrowserPage::class)
            ->call('toggleExpand', $memory->id)
            ->html();

        $this->assertSame(1, preg_match('/x-data="(\{ editing: false, tagInput: [^"]*\})"/', $html, $m));
        $this->assertSame("{ editing: false, tagInput: 'x\\u0027, y: alert(document.domain), z: \\u0027' }", $m[1]);
    }

    public function test_experiment_title_cannot_break_out_of_the_share_call(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn () => true);
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'status' => ExperimentStatus::Completed,
            'title' => self::PAYLOAD,
            'thesis' => self::PAYLOAD,
        ]);

        $html = Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])->html();

        $this->assertSame(1, preg_match('/@click="(share\([^"]*\))"/', $html, $m));
        $this->assertStringStartsWith("share('x\\u0027, y: alert(document.domain), z: \\u0027', 'x\\u0027,", $m[1]);
    }
}
