<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use Illuminate\Database\Seeder;

class BuiltInGuardrailsSeeder extends Seeder
{
    private array $guardrails = [
        [
            'name' => 'PII Detector',
            'slug' => 'guardrail-pii-detector',
            'description' => 'Detects personally identifiable information (email, phone, SSN, credit card) in input data before execution.',
            'system_prompt' => null, // Rule-based, no LLM needed
            'configuration' => ['mode' => 'rule_based'],
        ],
        [
            'name' => 'Toxicity Filter',
            'slug' => 'guardrail-toxicity-filter',
            'description' => 'Uses LLM-based content moderation to detect harmful, offensive, or inappropriate content.',
            'system_prompt' => 'You are a content moderation guardrail. Analyze input for toxicity, hate speech, self-harm content, or inappropriate material. Be strict but fair.',
            'configuration' => ['mode' => 'llm_based', 'temperature' => 0.0],
        ],
        [
            'name' => 'Budget Guard',
            'slug' => 'guardrail-budget-guard',
            'description' => 'Blocks execution when estimated cost exceeds a configured credit threshold.',
            'system_prompt' => null, // Rule-based, no LLM needed
            'configuration' => ['mode' => 'rule_based', 'max_cost_credits' => 1000],
        ],
        [
            'name' => 'Output Length Guard',
            'slug' => 'guardrail-output-length-guard',
            'description' => 'Rejects unusually long outputs that may indicate prompt injection or runaway generation.',
            'system_prompt' => null, // Rule-based, no LLM needed
            'configuration' => ['mode' => 'rule_based', 'max_length' => 50000],
        ],
    ];

    public function run(): void
    {
        $team = Team::first();

        if (! $team) {
            $this->command?->warn('BuiltInGuardrailsSeeder: No team found, skipping.');

            return;
        }

        foreach ($this->guardrails as $guardrail) {
            Skill::updateOrCreate(
                ['slug' => $guardrail['slug']],
                [
                    'team_id' => $team->id,
                    'name' => $guardrail['name'],
                    'description' => $guardrail['description'],
                    'type' => SkillType::Guardrail,
                    'status' => 'active',
                    'risk_level' => RiskLevel::Low,
                    'system_prompt' => $guardrail['system_prompt'],
                    'configuration' => $guardrail['configuration'],
                    'input_schema' => null,
                    'output_schema' => null,
                ],
            );
        }

        $this->command?->info('BuiltInGuardrailsSeeder: Seeded '.count($this->guardrails).' guardrail skills.');
    }
}
