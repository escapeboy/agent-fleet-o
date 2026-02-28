<?php

namespace Database\Seeders;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Seeder;

class BuiltInAgentTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first();

        if (! $team) {
            $this->command?->warn('BuiltInAgentTemplatesSeeder: No team found, skipping.');

            return;
        }

        $templates = config('agent-templates', []);

        if (empty($templates)) {
            $this->command?->warn('BuiltInAgentTemplatesSeeder: No templates found in config/agent-templates.php.');

            return;
        }

        $created = 0;
        $updated = 0;

        foreach ($templates as $tpl) {
            $slug = $tpl['slug'] ?? null;

            if (! $slug) {
                continue;
            }

            $exists = Agent::where('team_id', $team->id)->where('slug', $slug)->exists();

            Agent::updateOrCreate(
                ['team_id' => $team->id, 'slug' => $slug],
                [
                    'name' => $tpl['name'],
                    'role' => $tpl['role'] ?? null,
                    'goal' => $tpl['goal'] ?? null,
                    'backstory' => $tpl['backstory'] ?? null,
                    'personality' => $tpl['personality'] ?? null,
                    'provider' => $tpl['provider'] ?? 'anthropic',
                    'model' => $tpl['model'] ?? 'claude-haiku-4-5',
                    'status' => AgentStatus::Active,
                    'capabilities' => $tpl['capabilities'] ?? [],
                    'config' => ['is_template' => true, 'category' => $tpl['category'] ?? null, 'icon' => $tpl['icon'] ?? null],
                ],
            );

            $exists ? $updated++ : $created++;
        }

        $this->command?->info(
            "BuiltInAgentTemplatesSeeder: Created {$created}, updated {$updated} agent templates.",
        );
    }
}
