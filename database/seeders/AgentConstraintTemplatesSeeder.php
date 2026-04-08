<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use Illuminate\Database\Seeder;

class AgentConstraintTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first();

        if (! $team) {
            $this->command?->warn('AgentConstraintTemplatesSeeder: No team found, skipping.');

            return;
        }

        $templates = config('agent-constraint-templates', []);

        foreach ($templates as $template) {
            $systemPrompt = implode("\n", array_map(
                fn (string $rule) => '- '.$rule,
                $template['rules'],
            ));

            Skill::updateOrCreate(
                ['slug' => 'constraint-'.$template['slug']],
                [
                    'team_id' => $team->id,
                    'name' => $template['name'].' (Constraint)',
                    'description' => $template['description'],
                    'type' => SkillType::Guardrail,
                    'status' => 'active',
                    'risk_level' => RiskLevel::Low,
                    'system_prompt' => $systemPrompt,
                    'configuration' => ['source' => 'constraint_template', 'slug' => $template['slug']],
                    'input_schema' => ['type' => 'object', 'properties' => ['input' => ['type' => 'string']]],
                    'output_schema' => ['type' => 'object', 'properties' => ['passed' => ['type' => 'boolean'], 'reason' => ['type' => 'string']]],
                ],
            );
        }

        $this->command?->info('AgentConstraintTemplatesSeeder: Seeded '.count($templates).' constraint template skills.');
    }
}
