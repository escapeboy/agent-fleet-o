<?php

namespace App\Console\Commands;

use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Jobs\AnalyzeAgentFeedbackJob;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentFeedback;
use Illuminate\Console\Command;

class AnalyzeAgentFeedback extends Command
{
    protected $signature = 'agents:analyze-feedback';

    protected $description = 'Analyze accumulated negative feedback and generate EvolutionProposals for underperforming agents';

    public function handle(): int
    {
        // Find agents with enough recent negative feedback to warrant analysis
        $candidateAgentIds = AgentFeedback::withoutGlobalScopes()
            ->where('score', '<=', FeedbackRating::Neutral->value)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('agent_id, count(*) as negative_count, avg(score) as avg_score')
            ->groupBy('agent_id')
            ->having('negative_count', '>=', 5)
            ->pluck('agent_id');

        if ($candidateAgentIds->isEmpty()) {
            $this->info('No agents with sufficient negative feedback to analyze.');

            return self::SUCCESS;
        }

        $agents = Agent::withoutGlobalScopes()
            ->whereIn('id', $candidateAgentIds)
            ->get();

        $dispatched = 0;

        foreach ($agents as $agent) {
            dispatch(new AnalyzeAgentFeedbackJob($agent));
            $dispatched++;
            $this->line("  [QUEUED] {$agent->name} ({$agent->id})");
        }

        $this->info("Queued feedback analysis for {$dispatched} agent(s).");

        return self::SUCCESS;
    }
}
