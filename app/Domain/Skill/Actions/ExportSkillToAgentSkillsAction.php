<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Render a Skill as a portable agentskills.io SKILL.md document.
 *
 * The format (https://agentskills.io/specification) is YAML frontmatter
 * (`name`, `description` required) followed by Markdown instructions.
 * FleetQ-specific fields are preserved under `metadata.fleetq` so the
 * document can be re-imported losslessly via ImportSkillFromAgentSkillsAction.
 */
class ExportSkillToAgentSkillsAction
{
    public function execute(Skill $skill): string
    {
        $name = $this->sanitizeName($skill->slug ?: $skill->name);

        // Spec requires a non-empty description (max 1024 chars).
        $description = trim((string) ($skill->description ?: $skill->name));
        if ($description === '') {
            $description = $name;
        }
        $description = Str::limit($description, 1024, '');

        $frontmatter = [
            'name' => $name,
            'description' => $description,
            'compatibility' => Str::limit('FleetQ skill (type: '.$skill->type->value.').', 500, ''),
            'metadata' => [
                'fleetq' => [
                    'type' => $skill->type->value,
                    'framework' => $skill->framework?->value,
                    'execution_type' => $skill->execution_type->value,
                    'risk_level' => $skill->risk_level->value,
                    'status' => $skill->status->value,
                    'current_version' => $skill->current_version,
                    'input_schema' => $skill->input_schema ?? [],
                    'output_schema' => $skill->output_schema ?? [],
                    'configuration' => $skill->configuration ?? [],
                ],
            ],
        ];

        // Spec recommends a non-empty Markdown body of instructions.
        $body = trim((string) $skill->system_prompt);
        if ($body === '') {
            $body = "# {$name}\n\n{$description}";
        }

        $yaml = rtrim(Yaml::dump($frontmatter, 6, 2));

        return "---\n{$yaml}\n---\n\n{$body}\n";
    }

    /**
     * Coerce a FleetQ slug/name into a spec-valid skill name:
     * max 64 chars, lowercase letters/numbers/hyphens, no leading/trailing hyphen.
     */
    private function sanitizeName(string $raw): string
    {
        $name = strtolower($raw);
        $name = (string) preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = (string) preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');
        $name = substr($name, 0, 64);
        $name = trim($name, '-');

        return $name !== '' ? $name : 'fleetq-skill';
    }
}
