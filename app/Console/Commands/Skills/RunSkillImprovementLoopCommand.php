<?php

namespace App\Console\Commands\Skills;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Services\MetricGatedImprovementLoopService;
use Illuminate\Console\Command;

class RunSkillImprovementLoopCommand extends Command
{
    protected $signature = 'skill:improve-loop
                            {skillId : UUID of the skill to improve}
                            {--iterations=5 : Maximum number of improvement iterations}
                            {--metric=accuracy : Metric name to optimise (e.g. accuracy, quality, reliability)}';

    protected $description = 'Run the metric-gated improvement loop for a skill';

    public function handle(MetricGatedImprovementLoopService $service): int
    {
        $skill = Skill::withoutGlobalScopes()->find($this->argument('skillId'));

        if (! $skill) {
            $this->error("Skill [{$this->argument('skillId')}] not found.");

            return self::FAILURE;
        }

        $iterations = (int) $this->option('iterations');
        $metric = (string) $this->option('metric');

        $this->info("Starting improvement loop for skill \"{$skill->name}\" (metric={$metric}, max_iterations={$iterations})...");

        $service->run($skill, $iterations, $metric);

        $this->info('Improvement loop dispatched. Monitor progress via the Skill detail page or SkillBenchmark records.');

        return self::SUCCESS;
    }
}
