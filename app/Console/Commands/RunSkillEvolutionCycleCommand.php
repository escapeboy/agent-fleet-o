<?php

namespace App\Console\Commands;

use App\Domain\Evolution\Actions\EvolveSkillAction;
use App\Domain\Skill\Models\Skill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunSkillEvolutionCycleCommand extends Command
{
    protected $signature = 'skills:evolve';

    protected $description = 'Run GEPA evolution cycle for eligible skills (>= 10 executions, not evolved in 7 days).';

    public function handle(EvolveSkillAction $action): int
    {
        $skills = Skill::withoutGlobalScopes()
            ->where('execution_count', '>=', 10)
            ->where(function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('evolution_proposals')
                        ->whereColumn('evolution_proposals.skill_id', 'skills.id')
                        ->where('evolution_proposals.trigger', 'gepa_cycle')
                        ->where('evolution_proposals.created_at', '>=', now()->subDays(7));
                });
            })
            ->limit(50)
            ->get();

        $this->info("Running GEPA evolution for {$skills->count()} skill(s).");

        foreach ($skills as $skill) {
            $proposals = $action->execute($skill);
            $this->line("  Skill [{$skill->name}]: {$proposals->count()} proposal(s) queued.");
        }

        return self::SUCCESS;
    }
}
