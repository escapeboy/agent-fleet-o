<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\WorldModel\Models\TeamWorldModel;
use Closure;

/**
 * Injects the per-team "world model" digest (a short briefing summarising
 * recent signals, experiments, and memories) into the agent system prompt.
 *
 * Runs after memory/KG injection so the world-model snapshot sits at the
 * bottom of the context stack as "team-wide background".
 */
class InjectWorldModel
{
    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        if (! $ctx->teamId) {
            return $next($ctx);
        }

        $model = TeamWorldModel::withoutGlobalScopes()
            ->where('team_id', $ctx->teamId)
            ->first();

        if ($model === null || ! is_string($model->digest) || trim($model->digest) === '') {
            return $next($ctx);
        }

        $digest = trim($model->digest);
        $generatedAt = $model->generated_at?->toDateString() ?? 'n/a';

        $ctx->systemPromptParts[] = <<<BLOCK
        ## Team world-model (auto-generated, {$generatedAt})
        {$digest}
        BLOCK;

        return $next($ctx);
    }
}
