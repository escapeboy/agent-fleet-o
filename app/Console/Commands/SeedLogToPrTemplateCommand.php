<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\LogToPrTemplateBuilder;
use Illuminate\Console\Command;

class SeedLogToPrTemplateCommand extends Command
{
    protected $signature = 'workflow:seed-log-to-pr
        {--team= : Team UUID. Seeds the template for one team.}
        {--all : Seed for every team that does not already have the template.}
        {--activate : Set the workflow status to Active immediately. Without this flag the template stays in Draft.}
        {--force : Overwrite an existing log-to-pr workflow on the team.}';

    protected $description = 'Seed the default log-to-pr workflow template per team (borrowed from prilog.ai). Default-off — operators must pass --activate to flip it to Active.';

    public function handle(LogToPrTemplateBuilder $builder): int
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
                ->where('name', 'log-to-pr')
                ->first();

            if ($existing && ! $this->option('force')) {
                $this->line("[skip] Team {$team->id} already has a log-to-pr workflow ({$existing->id}). Use --force to overwrite.");
                $skipped++;

                continue;
            }

            if ($existing && $this->option('force')) {
                $existing->edges()->delete();
                $existing->nodes()->delete();
                $existing->delete();
            }

            try {
                $workflow = $builder->buildForTeam($team->id, $team->owner_id ?? null);

                $errors = $builder->validate($workflow);

                if (! empty($errors)) {
                    $this->error("[fail] Team {$team->id}: validator rejected the seeded template:");
                    foreach ($errors as $error) {
                        $this->error('  - '.($error['message'] ?? json_encode($error)));
                    }
                    $workflow->edges()->delete();
                    $workflow->nodes()->delete();
                    $workflow->delete();
                    $failed++;

                    continue;
                }

                if ($this->option('activate')) {
                    $workflow->update(['status' => WorkflowStatus::Active]);
                    $this->info("[done] Team {$team->id}: workflow {$workflow->id} seeded and activated.");
                } else {
                    $this->info("[done] Team {$team->id}: workflow {$workflow->id} seeded (Draft — pass --activate to flip Active).");
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
