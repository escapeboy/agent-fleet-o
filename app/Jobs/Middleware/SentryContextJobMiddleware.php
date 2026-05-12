<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Infrastructure\Telemetry\Sentry\SentryContext;
use Closure;
use Sentry\State\HubInterface;
use Throwable;

/**
 * Queue middleware that pushes FleetQ sub-program context onto the active
 * Sentry scope for the duration of a job, then restores the prior scope.
 *
 * Uses `Hub::withScope()` so context is isolated per job execution — critical
 * because Laravel queue workers run multiple jobs serially in the same process.
 * Without isolation, scope tags would leak from one job to the next.
 *
 * Job opt-in: any job class implementing `HasSentryContext` declares the
 * context fields it wants pushed. Jobs that don't implement the interface get
 * a minimal context (job class name + queue) so unhandled exceptions still
 * report which job failed.
 *
 * Side effect: also tags Laravel's logging context for the duration of the job
 * so `Log::error(...)` lines pick up team_id/experiment_id automatically.
 */
final class SentryContextJobMiddleware
{
    public function __construct(
        private readonly SentryContext $context,
        private readonly HubInterface $hub,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        $context = $this->resolveContext($job);

        $result = null;
        $thrown = null;

        $this->hub->withScope(function ($scope) use ($job, $next, $context, &$result, &$thrown): void {
            $this->context->apply($scope, $context);

            try {
                $result = $next($job);
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        if ($thrown !== null) {
            throw $thrown;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContext(object $job): array
    {
        $base = [
            'job' => $job::class,
            'queue' => property_exists($job, 'queue') ? ($job->queue ?? null) : null,
        ];

        if ($job instanceof HasSentryContext) {
            return array_merge($base, $job->sentryContext());
        }

        // Best-effort: pick up common public/readonly properties.
        $candidates = [
            'team_id' => ['teamId', 'team_id'],
            'experiment_id' => ['experimentId', 'experiment_id'],
            'agent_id' => ['agentId', 'agent_id'],
            'crew_execution_id' => ['crewExecutionId'],
            'workflow_id' => ['workflowId'],
            'workflow_node_id' => ['workflowNodeId', 'nodeId'],
            'project_run_id' => ['projectRunId'],
            'signal_id' => ['signalId'],
            'tool_id' => ['toolId'],
            'skill_id' => ['skillId'],
        ];

        foreach ($candidates as $contextKey => $propertyNames) {
            foreach ($propertyNames as $name) {
                if (property_exists($job, $name) && ! empty($job->{$name})) {
                    $base[$contextKey] = $job->{$name};
                    break;
                }
            }
        }

        return $base;
    }
}
