<?php

namespace App\Domain\Evolution\Actions;

use App\Domain\Evolution\Jobs\EvaluateSkillMutationJob;
use App\Domain\Evolution\Services\GEPAOptimizer;
use App\Domain\Skill\Models\Skill;
use Illuminate\Support\Collection;

class EvolveSkillAction
{
    public function __construct(private readonly GEPAOptimizer $optimizer) {}

    public function execute(Skill $skill, int $populationSize = 5): Collection
    {
        $proposals = $this->optimizer->run($skill, $populationSize);

        foreach ($proposals as $proposal) {
            EvaluateSkillMutationJob::dispatch($proposal->id);
        }

        return $proposals;
    }
}
