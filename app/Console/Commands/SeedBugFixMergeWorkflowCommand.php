<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\BugFixMergeTemplateBuilder;
use Illuminate\Console\Command;

class SeedBugFixMergeWorkflowCommand extends Command
{
    protected $signature = 'workflow:seed-bug-fix-merge
        {--team= : Team UUID. Seeds the template for one team.}
        {--all : Seed for every team that does not already have the template.}
        {--activate : Set the team\'s default_bug_fix_workflow_id to the seeded workflow.}
        {--force : Overwrite an existing bug-fix-merge workflow on the team.}';

    protected $description = 'Seed the default bug-fix-merge workflow template per team. Default-off — operators must pass --activate to wire the team\'s default_bug_fix_workflow_id pointer.';

    public function handle(BugFixMergeTemplateBuilder $builder): int
    {
        if (! $this->option('team') && ! $this->option('all')) {
            $this->error('Pass --team={uuid} or --all.');

            return self::FAILURE;
        }

        $teams = $this->option('all')
            ? Team::query()->withoutGlobalScopes()->get()
            : Team::query()->withoutGlobalScopes()
                ->where('id', $this->option('team'))
                ->get();

        if ($teams->isEmpty()) {
            $this->error('No teams matched.');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($teams as $team) {
            $existing = Workflow::query()
                ->withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('name', 'bug-fix-merge')
                ->first();

            if ($existing && ! $this->option('force')) {
                $this->line("[skip] Team {$team->id} already has a bug-fix-merge workflow ({$existing->id}). Use --force to overwrite.");
                $skipped++;

                continue;
            }

            if ($existing && $this->option('force')) {
                $existing->edges()->delete();
                $existing->nodes()->delete();
                $existing->delete();
            }

            try {
                $workflow = $builder->buildForTeam($team->id, $team->owner_id);

                $errors = $builder->validate($workflow);

                if (! empty($errors)) {
                    $this->error("[fail] Team {$team->id}: validator rejected the seeded template:");
                    foreach ($errors as $error) {
                        $this->error('  - '.$error['message']);
                    }
                    $workflow->edges()->delete();
                    $workflow->nodes()->delete();
                    $workflow->delete();
                    $failed++;

                    continue;
                }

                if ($this->option('activate')) {
                    $team->update(['default_bug_fix_workflow_id' => $workflow->id]);
                    $this->info("[done] Team {$team->id}: workflow {$workflow->id} seeded and activated.");
                } else {
                    $this->info("[done] Team {$team->id}: workflow {$workflow->id} seeded (not activated — pass --activate to wire team default).");
                }

                $created++;
            } catch (\Throwable $e) {
                $this->error("[fail] Team {$team->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->table(['Created', 'Skipped', 'Failed'], [[$created, $skipped, $failed]]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
