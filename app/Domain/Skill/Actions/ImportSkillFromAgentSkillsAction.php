<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Create a Skill from a portable agentskills.io SKILL.md document.
 *
 * Required frontmatter: `name`, `description`. FleetQ-specific fields are
 * restored from `metadata.fleetq` when present (round-trips an export);
 * otherwise safe defaults are used (LLM skill, sync, low risk) and the
 * Markdown body becomes the system prompt.
 */
class ImportSkillFromAgentSkillsAction
{
    public function __construct(private readonly CreateSkillAction $createSkill) {}

    public function execute(string $teamId, string $skillMd, ?string $createdBy = null): Skill
    {
        [$frontmatter, $body] = $this->split($skillMd);

        $name = trim((string) ($frontmatter['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('SKILL.md frontmatter is missing a non-empty "name".');
        }

        $description = trim((string) ($frontmatter['description'] ?? ''));
        if ($description === '') {
            throw new InvalidArgumentException('SKILL.md frontmatter is missing a non-empty "description".');
        }

        $fleetq = $frontmatter['metadata']['fleetq'] ?? [];

        return $this->createSkill->execute(
            teamId: $teamId,
            name: $name,
            type: SkillType::tryFrom((string) ($fleetq['type'] ?? '')) ?? SkillType::Llm,
            description: $description,
            executionType: ExecutionType::tryFrom((string) ($fleetq['execution_type'] ?? '')) ?? ExecutionType::Sync,
            riskLevel: RiskLevel::tryFrom((string) ($fleetq['risk_level'] ?? '')) ?? RiskLevel::Low,
            inputSchema: is_array($fleetq['input_schema'] ?? null) ? $fleetq['input_schema'] : [],
            outputSchema: is_array($fleetq['output_schema'] ?? null) ? $fleetq['output_schema'] : [],
            configuration: is_array($fleetq['configuration'] ?? null) ? $fleetq['configuration'] : [],
            systemPrompt: $body !== '' ? $body : null,
            createdBy: $createdBy,
        );
    }

    /**
     * Split a SKILL.md string into its parsed frontmatter mapping and Markdown body.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function split(string $skillMd): array
    {
        $content = ltrim($skillMd);

        if (! str_starts_with($content, '---')) {
            throw new InvalidArgumentException('SKILL.md must begin with YAML frontmatter delimited by "---".');
        }

        $afterOpen = (string) preg_replace('/^---\s*\n/', '', $content, 1);
        $parts = preg_split('/^---\s*$/m', $afterOpen, 2);

        if (! is_array($parts) || count($parts) < 2) {
            throw new InvalidArgumentException('SKILL.md frontmatter is not closed by a "---" line.');
        }

        $frontmatter = Yaml::parse(trim($parts[0])) ?? [];
        if (! is_array($frontmatter)) {
            throw new InvalidArgumentException('SKILL.md frontmatter is not a valid YAML mapping.');
        }

        return [$frontmatter, trim($parts[1])];
    }
}
