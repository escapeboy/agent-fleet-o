<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry\Sentry;

use Throwable;

/**
 * Computes per-sub-program Sentry fingerprints.
 *
 * Default Sentry grouping uses {exception_class, stack_top}. For a multi-tenant
 * agent platform that produces the wrong granularity:
 *   - Same OpenAI 429 across all teams should be ONE issue (not per-team).
 *   - Same RuntimeException from agent A vs agent B should be TWO issues
 *     (because the bug usually lives in the agent's prompt, not in code).
 *
 * Rules below are encoded so Sentry receives the right fingerprint *before*
 * the event leaves the SDK.
 *
 * Adding a new sub-program: add a case to resolve() that returns a fingerprint
 * array. The token `{{ default }}` tells Sentry to combine your tokens with
 * its own default grouping algorithm — useful when you only want to refine,
 * not replace, the grouping.
 */
final class FingerprintResolver
{
    /**
     * Resolve a fingerprint for the given exception and context.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, string> Fingerprint tokens. Empty array means "use Sentry default".
     */
    public function resolve(Throwable $exception, array $context): array
    {
        $subProgram = (string) ($context['sub_program'] ?? '');
        $exceptionClass = $exception::class;

        // Sub-program-specific rules, ordered by specificity.
        $rule = match (true) {
            // LLM failures: group by provider+model so a model regression is one issue,
            // not N-per-team.
            str_starts_with($subProgram, 'llm.') => [
                $exceptionClass,
                'sub_program:'.$subProgram,
                'provider:'.((string) ($context['provider'] ?? 'unknown')),
                'model:'.((string) ($context['model'] ?? 'unknown')),
            ],

            // Experiment stage failures: group by stage + agent. Same agent failing
            // in same stage = same issue, regardless of team.
            $subProgram === 'experiment.stage' => [
                $exceptionClass,
                'stage:'.((string) ($context['experiment_stage'] ?? 'unknown')),
                'agent:'.((string) ($context['agent_id'] ?? 'no-agent')),
            ],

            // Crew task failures: group by agent. Crew failures are almost always
            // agent-prompt problems, so per-agent grouping surfaces the offender.
            $subProgram === 'crew.task' => [
                $exceptionClass,
                'agent:'.((string) ($context['agent_id'] ?? 'no-agent')),
            ],

            // Workflow node failures: group by workflow + node. Same node failing
            // = same issue, even across runs.
            $subProgram === 'workflow.node' => [
                $exceptionClass,
                'workflow:'.((string) ($context['workflow_id'] ?? 'unknown')),
                'node:'.((string) ($context['workflow_node_id'] ?? 'unknown')),
            ],

            // Outbound channel failures (email/Slack/Telegram/webhook): group by channel.
            str_starts_with($subProgram, 'outbound.') => [
                $exceptionClass,
                'channel:'.substr($subProgram, strlen('outbound.')),
            ],

            // Integration driver failures: group by integration key.
            $subProgram === 'integration.execute' => [
                $exceptionClass,
                'integration:'.((string) ($context['integration_id'] ?? 'unknown')),
            ],

            // Project run failures (top-level): group by project. Per-project, not per-run.
            $subProgram === 'project.run' => [
                $exceptionClass,
                'project_run:'.((string) ($context['project_run_id'] ?? 'unknown')),
            ],

            // Assistant message processing: group by exception class only (typical
            // Laravel error grouping is fine here — usually code bugs).
            $subProgram === 'assistant.message' => [
                $exceptionClass,
                '{{ default }}',
            ],

            // Skill execution: group by skill_id. Skills are versioned, so a regression
            // surfaces as a single issue per skill version.
            $subProgram === 'skill.execute' => [
                $exceptionClass,
                'skill:'.((string) ($context['skill_id'] ?? 'unknown')),
            ],

            // Unknown sub-program: defer to Sentry's default grouping by returning
            // an empty array (no fingerprint override).
            default => [],
        };

        // Drop tokens that resolved to "unknown" placeholders to avoid grouping
        // unrelated events together under e.g. "agent:no-agent".
        return array_values(array_filter($rule, static fn ($token) => ! str_ends_with($token, ':unknown') && ! str_ends_with($token, ':no-agent') || $token === '{{ default }}'));
    }
}
