<?php

namespace App\Console\Commands\Skills;

use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Jobs\GenerateSkillEmbeddingJob;
use App\Domain\Skill\Models\Skill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexSkillEmbeddingsCommand extends Command
{
    protected $signature = 'skills:reindex
                            {--team= : Reindex only skills for a specific team ID}
                            {--force : Reindex all skills, even those with existing embeddings}';

    protected $description = 'Regenerate pgvector embeddings for all active skills';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->warn('Skill embeddings require PostgreSQL. Skipping.');

            return self::SUCCESS;
        }

        $query = Skill::query()->where('status', SkillStatus::Active);

        if ($teamId = $this->option('team')) {
            $query->where('team_id', $teamId);
        }

        if (! $this->option('force')) {
            $query->whereDoesntHave('embedding');
        }

        $count = $query->count();
        $this->info("Dispatching embedding jobs for {$count} skill(s)...");

        $query->each(function (Skill $skill) {
            GenerateSkillEmbeddingJob::dispatch($skill->id);
        });

        $this->info("Dispatched {$count} jobs to the 'default' queue.");

        return self::SUCCESS;
    }
}
