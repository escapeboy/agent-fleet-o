<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExportSkillToAgentSkillsAction;
use App\Domain\Skill\Actions\ImportSkillFromAgentSkillsAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class ImportSkillFromAgentSkillsActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // skip GenerateSkillEmbeddingJob (no real embeddings in tests)

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Import Team',
            'slug' => 'import-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    private function action(): ImportSkillFromAgentSkillsAction
    {
        return app(ImportSkillFromAgentSkillsAction::class);
    }

    private function skillMd(string $extraMetadata = '', string $type = 'guardrail'): string
    {
        return <<<MD
        ---
        name: my-guardrail
        description: Blocks unsafe output.
        metadata:
          fleetq:
            type: {$type}
            execution_type: sync
            risk_level: low
            configuration:
              foo: bar
        {$extraMetadata}
        ---

        # Guardrail

        Block anything unsafe.
        MD;
    }

    public function test_imports_a_valid_skill_md(): void
    {
        $skill = $this->action()->execute($this->team->id, $this->skillMd());

        $this->assertSame($this->team->id, $skill->team_id);
        $this->assertSame(SkillType::Guardrail, $skill->type);
        $this->assertSame('Blocks unsafe output.', $skill->description);
        $this->assertStringContainsString('Block anything unsafe.', (string) $skill->system_prompt);
        $this->assertSame('bar', $skill->configuration['foo']);
    }

    public function test_unknown_type_falls_back_to_llm(): void
    {
        $skill = $this->action()->execute($this->team->id, $this->skillMd(type: 'not_a_real_type'));

        $this->assertSame(SkillType::Llm, $skill->type);
    }

    public function test_missing_frontmatter_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->action()->execute($this->team->id, "# Just markdown\n\nNo frontmatter here.");
    }

    public function test_missing_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->action()->execute($this->team->id, "---\ndescription: Has no name.\n---\n\nBody.");
    }

    public function test_missing_description_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->action()->execute($this->team->id, "---\nname: nameless\n---\n\nBody.");
    }

    public function test_round_trip_preserves_core_fields(): void
    {
        $original = Skill::factory()->for($this->team)->create([
            'name' => 'Round Trip',
            'slug' => 'round-trip',
            'description' => 'Round trips cleanly.',
            'type' => SkillType::Guardrail,
            'system_prompt' => 'Original instructions here.',
            'configuration' => ['k' => 'v'],
        ]);

        // Portability: a skill exported from one team is imported into another.
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $this->team->owner_id,
            'settings' => [],
        ]);

        $md = app(ExportSkillToAgentSkillsAction::class)->execute($original);
        $imported = $this->action()->execute($otherTeam->id, $md);

        $this->assertSame($otherTeam->id, $imported->team_id);
        $this->assertSame(SkillType::Guardrail, $imported->type);
        $this->assertSame('Round trips cleanly.', $imported->description);
        $this->assertStringContainsString('Original instructions here.', (string) $imported->system_prompt);
        $this->assertSame('v', $imported->configuration['k']);
    }
}
