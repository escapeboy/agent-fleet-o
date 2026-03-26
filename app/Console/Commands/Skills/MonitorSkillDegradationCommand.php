<?php

namespace App\Console\Commands\Skills;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Models\Skill;
use Illuminate\Console\Command;

class MonitorSkillDegradationCommand extends Command
{
    protected $signature = 'skills:monitor-degradation
                            {--dry-run : Show degraded skills without creating proposals}';

    protected $description = 'Scan active skills for quality degradation and auto-create EvolutionProposals';

    public function handle(): int
    {
        $minSample = config('skills.degradation.min_sample_size', 10);
        $reliabilityThreshold = config('skills.degradation.reliability_threshold', 0.6);
        $qualityThreshold = config('skills.degradation.quality_threshold', 0.5);
        $isDryRun = $this->option('dry-run');

        $degradedCount = 0;
        $proposalsCreated = 0;

        Skill::withoutGlobalScopes()
            ->where('status', SkillStatus::Active)
            ->where('applied_count', '>=', $minSample)
            ->each(function (Skill $skill) use (
                $reliabilityThreshold,
                $qualityThreshold,
                $isDryRun,
                &$degradedCount,
                &$proposalsCreated,
            ) {
                $reliabilityRate = $skill->reliability_rate;
                $qualityRate = $skill->quality_rate;

                $isDegraded = $reliabilityRate < $reliabilityThreshold
                    || $qualityRate < $qualityThreshold;

                if (! $isDegraded) {
                    return;
                }

                $degradedCount++;

                $this->line(sprintf(
                    '  [%s] reliability=%.0f%% quality=%.0f%% (team: %s)',
                    $skill->name,
                    $reliabilityRate * 100,
                    $qualityRate * 100,
                    $skill->team_id,
                ));

                if ($isDryRun) {
                    return;
                }

                // Avoid duplicate pending proposals for the same skill
                $existingPending = EvolutionProposal::withoutGlobalScopes()
                    ->where('skill_id', $skill->id)
                    ->where('status', EvolutionProposalStatus::Pending)
                    ->exists();

                if ($existingPending) {
                    return;
                }

                // agent_id is a required FK; resolve the first agent for this team as the proposal owner
                $agentId = Agent::withoutGlobalScopes()
                    ->where('team_id', $skill->team_id)
                    ->value('id');

                if ($agentId === null) {
                    $this->warn(sprintf(
                        '  [SKIP] %s — no agents found for team %s, cannot create proposal.',
                        $skill->name,
                        $skill->team_id,
                    ));

                    return;
                }

                $issues = [];

                if ($reliabilityRate < $reliabilityThreshold) {
                    $issues[] = sprintf(
                        'low reliability rate (%.0f%% < %.0f%%)',
                        $reliabilityRate * 100,
                        $reliabilityThreshold * 100,
                    );
                }

                if ($qualityRate < $qualityThreshold) {
                    $issues[] = sprintf(
                        'low quality rate (%.0f%% < %.0f%%)',
                        $qualityRate * 100,
                        $qualityThreshold * 100,
                    );
                }

                EvolutionProposal::create([
                    'team_id' => $skill->team_id,
                    'agent_id' => $agentId,
                    'skill_id' => $skill->id,
                    'trigger' => 'degradation_monitor',
                    'status' => EvolutionProposalStatus::Pending,
                    'analysis' => sprintf(
                        'Automated degradation detection: skill "%s" shows %s based on %d executions (applied=%d, completed=%d, effective=%d).',
                        $skill->name,
                        implode(' and ', $issues),
                        $skill->applied_count,
                        $skill->applied_count,
                        $skill->completed_count,
                        $skill->effective_count,
                    ),
                    'proposed_changes' => [
                        'type' => 'fix',
                        'metrics' => [
                            'reliability_rate' => $reliabilityRate,
                            'quality_rate' => $qualityRate,
                            'applied_count' => $skill->applied_count,
                        ],
                    ],
                    'reasoning' => 'Skill performance metrics have fallen below configured thresholds. Review system prompt, input schema, and recent execution logs to identify degradation cause.',
                    'confidence_score' => max(0.5, 1.0 - $reliabilityRate - $qualityRate),
                ]);

                $proposalsCreated++;
            });

        if ($isDryRun) {
            $this->info("Dry-run: found {$degradedCount} degraded skill(s).");
        } else {
            $this->info("Scanned skills: {$degradedCount} degraded, {$proposalsCreated} proposals created.");
        }

        return self::SUCCESS;
    }
}
