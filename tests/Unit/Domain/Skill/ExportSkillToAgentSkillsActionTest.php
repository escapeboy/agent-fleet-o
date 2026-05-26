<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Actions\ExportSkillToAgentSkillsAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ExportSkillToAgentSkillsActionTest extends TestCase
{
    private ExportSkillToAgentSkillsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ExportSkillToAgentSkillsAction;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function parse(string $md): array
    {
        $this->assertStringStartsWith("---\n", $md);
        $after = (string) preg_replace('/^---\s*\n/', '', $md, 1);
        $parts = preg_split('/^---\s*$/m', $after, 2);

        return [Yaml::parse(trim($parts[0])), trim($parts[1])];
    }

    public function test_exports_valid_frontmatter_and_body(): void
    {
        $skill = Skill::factory()->make([
            'team_id' => 'team-1',
            'name' => 'Lead Scorer',
            'slug' => 'lead-scorer',
            'description' => 'Scores inbound leads from 0 to 100.',
            'type' => SkillType::Llm,
            'system_prompt' => 'You score leads from 0 to 100.',
        ]);

        [$fm, $body] = $this->parse($this->action->execute($skill));

        $this->assertSame('lead-scorer', $fm['name']);
        $this->assertSame('Scores inbound leads from 0 to 100.', $fm['description']);
        $this->assertSame('llm', $fm['metadata']['fleetq']['type']);
        $this->assertStringContainsString('score leads', $body);
    }

    public function test_sanitizes_name_to_spec(): void
    {
        $skill = Skill::factory()->make([
            'team_id' => 'team-1',
            'slug' => '',
            'name' => '  --My COOL Skill!! v2  ',
            'description' => 'desc',
        ]);

        [$fm] = $this->parse($this->action->execute($skill));

        $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $fm['name']);
        $this->assertLessThanOrEqual(64, strlen($fm['name']));
    }

    public function test_empty_description_falls_back_to_name(): void
    {
        $skill = Skill::factory()->make([
            'team_id' => 'team-1',
            'name' => 'No Desc Skill',
            'slug' => 'no-desc-skill',
            'description' => '',
        ]);

        [$fm] = $this->parse($this->action->execute($skill));

        $this->assertNotEmpty($fm['description']);
    }

    public function test_body_falls_back_when_system_prompt_empty(): void
    {
        $skill = Skill::factory()->make([
            'team_id' => 'team-1',
            'name' => 'Stub',
            'slug' => 'stub',
            'description' => 'A stub skill.',
            'system_prompt' => null,
        ]);

        [, $body] = $this->parse($this->action->execute($skill));

        $this->assertNotEmpty($body);
        $this->assertStringContainsString('stub', $body);
    }

    public function test_description_is_truncated_to_spec_max(): void
    {
        $skill = Skill::factory()->make([
            'team_id' => 'team-1',
            'name' => 'Long',
            'slug' => 'long',
            'description' => str_repeat('x', 1500),
        ]);

        [$fm] = $this->parse($this->action->execute($skill));

        $this->assertLessThanOrEqual(1024, mb_strlen($fm['description']));
    }
}
