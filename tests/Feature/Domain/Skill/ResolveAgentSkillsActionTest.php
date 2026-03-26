<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ResolveAgentSkillsAction;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveAgentSkillsActionTest extends TestCase
{
    use RefreshDatabase;

    private ResolveAgentSkillsAction $action;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ResolveAgentSkillsAction::class);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
    }

    private function makeSkill(array $overrides = []): Skill
    {
        return Skill::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Generic Skill',
            'slug' => 'generic-skill-'.uniqid(),
            'type' => 'llm',
            'status' => 'active',
            'configuration' => [],
            'applied_count' => 0,
            'completed_count' => 0,
            'effective_count' => 0,
            'fallback_count' => 0,
        ], $overrides));
    }

    public function test_returns_empty_collection_when_hybrid_retrieval_disabled(): void
    {
        config(['skills.hybrid_retrieval.enabled' => false]);

        $this->makeSkill(['name' => 'Translation Skill', 'description' => 'Translates text']);

        $result = $this->action->execute($this->team->id, 'translate some text');

        $this->assertCount(0, $result);
    }

    public function test_returns_skills_matching_task_description_via_like_search(): void
    {
        config(['skills.hybrid_retrieval.enabled' => true]);

        $this->makeSkill([
            'name' => 'Translation Skill',
            'slug' => 'translation-skill',
            'description' => 'Translates text between languages',
        ]);

        $result = $this->action->execute($this->team->id, 'translate some text');

        $this->assertGreaterThanOrEqual(1, $result->count());
        $this->assertTrue($result->contains(fn ($s) => str_contains($s->name, 'Translation')));
    }

    public function test_returns_empty_collection_when_no_skills_match(): void
    {
        config(['skills.hybrid_retrieval.enabled' => true]);

        $this->makeSkill([
            'name' => 'Image Processing Skill',
            'description' => 'Processes images and extracts visual data',
        ]);

        // Query has no overlap with the skill name/description
        $result = $this->action->execute($this->team->id, 'zxqwerty unrelated query');

        $this->assertCount(0, $result);
    }

    public function test_increments_applied_count_on_matched_skills(): void
    {
        config(['skills.hybrid_retrieval.enabled' => true]);

        $skill = $this->makeSkill([
            'name' => 'Summarization Skill',
            'slug' => 'summarization-skill',
            'description' => 'Summarizes long documents into short summaries',
            'applied_count' => 0,
        ]);

        $this->action->execute($this->team->id, 'summarize this document');

        $this->assertDatabaseHas('skills', [
            'id' => $skill->id,
            'applied_count' => 1,
        ]);
    }

    public function test_returns_at_most_max_injected_skills(): void
    {
        config(['skills.hybrid_retrieval.enabled' => true]);
        config(['skills.hybrid_retrieval.max_injected' => 2]);

        // Create 4 matching skills
        foreach (range(1, 4) as $i) {
            $this->makeSkill([
                'name' => "Coding Skill {$i}",
                'slug' => "coding-skill-{$i}",
                'description' => 'Writes and reviews code and programming tasks',
            ]);
        }

        $result = $this->action->execute($this->team->id, 'write some code');

        $this->assertLessThanOrEqual(2, $result->count());
    }

    public function test_scopes_results_to_correct_team_id(): void
    {
        config(['skills.hybrid_retrieval.enabled' => true]);

        // Create a skill for another team
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        Skill::create([
            'team_id' => $otherTeam->id,
            'name' => 'Translation Skill Other',
            'slug' => 'translation-skill-other',
            'description' => 'Translates text between languages',
            'type' => 'llm',
            'status' => 'active',
            'configuration' => [],
            'applied_count' => 0,
            'completed_count' => 0,
            'effective_count' => 0,
            'fallback_count' => 0,
        ]);

        // No skills for $this->team
        $result = $this->action->execute($this->team->id, 'translate some text');

        // Should not return the other team's skill
        $this->assertCount(0, $result);
        $this->assertFalse($result->contains(fn ($s) => $s->team_id === $otherTeam->id));
    }
}
