<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\SkillPlaygroundRunAction;
use App\Domain\Skill\Actions\StartSkillBenchmarkAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The playground and the benchmark post the skill's prompt_template to the LLM
 * gateway and bill for the call. Hiding the tabs guards nothing: the MCP tool
 * skill_playground_test and the Livewire startBenchmark() method both reach the
 * actions directly, so the guard belongs in the actions.
 */
class PlaygroundTypeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    }

    private function skill(SkillType $type): Skill
    {
        return Skill::factory()->create(['team_id' => $this->team->id, 'type' => $type->value]);
    }

    public function test_the_playground_refuses_a_decision_skill(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt template');

        app(SkillPlaygroundRunAction::class)->execute(
            skill: $this->skill(SkillType::Decision),
            input: 'x',
            models: ['anthropic/claude-haiku-4-5'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );
    }

    public function test_the_benchmark_refuses_a_decision_skill(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt template');

        app(StartSkillBenchmarkAction::class)->execute(
            skill: $this->skill(SkillType::Decision),
            userId: $this->user->id,
            metricName: 'accuracy',
            testInputs: [['input' => 'x']],
        );
    }

    /**
     * The allowed set is written out as a literal on purpose. Looping
     * SkillType::cases() and skipping anything the predicate calls true uses the
     * predicate as its own oracle: the loop passes for ANY classification,
     * including the wrong one it was first written against, which had
     * `connector` and `rule` marked true.
     *
     * These four are exactly the arms of ExecuteSkillAction::executeByType()
     * that route through buildUserPrompt(), which is the only reader of
     * configuration['prompt_template'].
     */
    public function test_exactly_four_types_consume_a_prompt_template(): void
    {
        $allowed = array_values(array_filter(
            array_map(fn (SkillType $t) => $t->usesPromptTemplate() ? $t->value : null, SkillType::cases()),
        ));

        sort($allowed);

        $this->assertSame(['guardrail', 'hybrid', 'llm', 'multi_model_consensus'], $allowed);
    }

    public function test_every_other_type_is_refused_by_the_playground(): void
    {
        $refused = 0;

        foreach (SkillType::cases() as $type) {
            if (in_array($type->value, ['llm', 'hybrid', 'guardrail', 'multi_model_consensus'], true)) {
                continue;
            }

            try {
                app(SkillPlaygroundRunAction::class)->execute(
                    skill: $this->skill($type),
                    input: 'x',
                    models: ['anthropic/claude-haiku-4-5'],
                    teamId: $this->team->id,
                    userId: $this->user->id,
                );
                $this->fail("{$type->value} was accepted by the playground.");
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }

        $this->assertSame(11, $refused);
    }

    /**
     * The guard throws from inside the action, so every caller has to handle it.
     * Three did not: this endpoint, SkillBenchmarkStartTool and SkillOpsPage.
     * A 500 on a public endpoint would be a regression introduced by the guard.
     */
    public function test_the_benchmark_api_returns_422_not_500_for_a_decision_skill(): void
    {
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $skill = $this->skill(SkillType::Decision);

        $this->actingAs($this->user)
            ->postJson("/api/v1/skills/{$skill->id}/benchmarks", [
                'metric_name' => 'accuracy',
                'test_inputs' => [['input' => 'x']],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'A benchmark measures a prompt template against the LLM gateway; a decision skill has none.']);
    }

    public function test_connector_and_rule_are_refused_because_they_build_their_own_prompt(): void
    {
        foreach ([SkillType::Connector, SkillType::Rule] as $type) {
            $this->assertFalse(
                $type->usesPromptTemplate(),
                "{$type->value} reaches the LLM gateway but builds its prompt from system_prompt plus its own config key, so a playground run measures a prompt production never sends.",
            );
        }
    }
}
