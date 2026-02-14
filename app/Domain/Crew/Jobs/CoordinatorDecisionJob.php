<?php

namespace App\Domain\Crew\Jobs;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Jobs\Middleware\TenantRateLimit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CoordinatorDecisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 15;

    public function __construct(
        public readonly string $crewExecutionId,
        public readonly ?string $teamId = null,
    ) {
        $this->onQueue('ai-calls');
    }

    public function middleware(): array
    {
        return [
            new CheckKillSwitch,
            new CheckBudgetAvailable,
            new TenantRateLimit('ai-calls', 30),
        ];
    }

    public function handle(
        AiGatewayInterface $gateway,
        ProviderResolver $providerResolver,
    ): void {
        $execution = CrewExecution::withoutGlobalScopes()->find($this->crewExecutionId);

        if (! $execution || $execution->status !== CrewExecutionStatus::Executing) {
            return;
        }

        $config = $execution->config_snapshot;
        $maxIterations = $config['max_task_iterations'] ?? 10;

        // Check iteration limit
        if ($execution->coordinator_iterations >= $maxIterations * 3) {
            $execution->update([
                'status' => CrewExecutionStatus::Terminated,
                'error_message' => 'Max coordinator iterations reached.',
                'completed_at' => now(),
            ]);

            // Try to synthesize partial results
            $validatedCount = $execution->taskExecutions()
                ->where('status', CrewTaskStatus::Validated->value)
                ->count();

            if ($validatedCount > 0) {
                app(CrewOrchestrator::class)->onTaskValidated($execution, new CrewTaskExecution);
            }

            return;
        }

        $coordinator = Agent::withoutGlobalScopes()->find($config['coordinator']['id']);
        if (! $coordinator) {
            $execution->update([
                'status' => CrewExecutionStatus::Failed,
                'error_message' => 'Coordinator agent not found.',
                'completed_at' => now(),
            ]);

            return;
        }

        $resolved = $providerResolver->resolve(agent: $coordinator);

        // Build context: completed tasks and their outputs
        $completedTasks = $execution->taskExecutions()
            ->whereIn('status', [CrewTaskStatus::Validated->value, CrewTaskStatus::QaFailed->value])
            ->orderBy('sort_order')
            ->get();

        $pendingTasks = $execution->taskExecutions()
            ->where('status', CrewTaskStatus::Pending->value)
            ->get();

        $completedSummary = $completedTasks->map(fn ($t) => "- {$t->title} [{$t->status->value}]: ".
            ($t->output ? json_encode($t->output, JSON_UNESCAPED_UNICODE) : 'No output'),
        )->implode("\n");

        $workerList = collect($config['workers'] ?? [])
            ->map(fn ($w) => "- {$w['name']} ({$w['role']}): {$w['goal']}")
            ->implode("\n");

        $isCoordinatorOnly = empty($config['workers']);

        $systemPrompt = "You are {$coordinator->role} managing a team. Decide the next action.\n\n"
            ."Available actions:\n"
            ."- \"delegate\": Assign a task to a team member (or yourself if solo)\n"
            ."- \"complete\": The goal is achieved, provide a summary\n\n"
            .'Respond with valid JSON: { "action": "delegate"|"complete", "task": { "title": string, "description": string }, "assigned_to": string, "summary": string }';

        $userPrompt = "Goal: {$execution->goal}\n\n"
            ."Completed tasks:\n".($completedSummary ?: 'None yet')."\n\n"
            .($pendingTasks->isNotEmpty() ? "Pending tasks (pre-planned):\n".$pendingTasks->map(fn ($t) => "- {$t->title}")->implode("\n")."\n\n" : '')
            .($isCoordinatorOnly
                ? "You are working alone. Assign tasks to 'self'.\n"
                : "Available agents:\n{$workerList}\n")
            ."\nIteration: ".($execution->coordinator_iterations + 1)
            ."\nWhat should we do next?";

        try {
            $request = new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                maxTokens: 2048,
                teamId: $execution->team_id,
                agentId: $coordinator->id,
                purpose: 'crew.coordinator_decision',
                temperature: 0.3,
            );

            $response = $gateway->complete($request);

            $execution->increment('coordinator_iterations');
            $execution->increment('total_cost_credits', $response->usage->costCredits);

            $decision = $this->parseDecision($response->content);

            if ($decision['action'] === 'complete') {
                // Coordinator says work is done — synthesize
                app(CrewOrchestrator::class)->synthesizeAndComplete($execution->fresh());

                return;
            }

            // Create and dispatch a new task
            $this->createAndDispatchTask($execution, $decision, $config, $coordinator, $isCoordinatorOnly);
        } catch (\Throwable $e) {
            Log::error('Coordinator decision failed', [
                'execution_id' => $this->crewExecutionId,
                'error' => $e->getMessage(),
            ]);

            $execution->update([
                'status' => CrewExecutionStatus::Failed,
                'error_message' => 'Coordinator decision failed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    private function createAndDispatchTask(
        CrewExecution $execution,
        array $decision,
        array $config,
        Agent $coordinator,
        bool $isCoordinatorOnly,
    ): void {
        $assignedTo = $decision['assigned_to'] ?? 'self';
        $assignedAgent = null;

        if ($assignedTo === 'self' || $isCoordinatorOnly) {
            $assignedAgent = $coordinator;
        } else {
            $worker = collect($config['workers'] ?? [])
                ->first(fn ($w) => strcasecmp($w['name'], $assignedTo) === 0);
            if ($worker) {
                $assignedAgent = Agent::withoutGlobalScopes()->find($worker['id']);
            }
        }

        // Fallback to coordinator if agent not found
        if (! $assignedAgent) {
            $assignedAgent = $coordinator;
        }

        $existingCount = $execution->taskExecutions()->count();

        $task = CrewTaskExecution::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => $assignedAgent->id,
            'title' => $decision['task']['title'] ?? 'Task '.($existingCount + 1),
            'description' => $decision['task']['description'] ?? '',
            'status' => CrewTaskStatus::Assigned,
            'input_context' => [
                'assigned_to' => $assignedTo,
                'coordinator_iteration' => $execution->coordinator_iterations,
            ],
            'depends_on' => [],
            'attempt_number' => 1,
            'max_attempts' => $config['max_task_iterations'] ?? 3,
            'sort_order' => $existingCount,
        ]);

        ExecuteCrewTaskJob::dispatch(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $execution->team_id,
        );
    }

    private function parseDecision(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
        }

        $parsed = json_decode($content, true, 512, JSON_UNESCAPED_UNICODE);

        if (! is_array($parsed) || ! isset($parsed['action'])) {
            throw new \RuntimeException('Coordinator did not produce a valid decision JSON.');
        }

        return $parsed;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CoordinatorDecisionJob failed', [
            'execution_id' => $this->crewExecutionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
