<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Services\ErrorTranslator;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Tools\Experiment\ExperimentDiagnoseTool;
use App\Mcp\Tools\Experiment\ExperimentResumeFromCheckpointTool;
use App\Mcp\Tools\Experiment\ExperimentResumeTool;
use App\Mcp\Tools\Experiment\ExperimentRetryTool;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Failure-context "🔍 Diagnose" + "💬 Ask Assistant" surface.
 *
 * Mounts under failed/paused detail pages (experiments today, more later)
 * and asks the matching diagnose MCP tool to translate the technical
 * failure into a customer-readable summary plus a list of recommended
 * recovery actions.
 *
 * The component itself only triggers the diagnosis and renders. Action
 * execution is delegated to the parent page (route nav, tool calls, or
 * assistant prompts) via dispatched events / regular links.
 */
class FixWithAssistant extends Component
{
    /** Supported entity types: 'experiment', 'project', 'agent', 'skill', 'crew', 'workflow'. */
    #[Locked]
    public string $entityType = '';

    #[Locked]
    public string $entityId = '';

    /** When false, the component renders nothing. Set in mount based on entity state. */
    public bool $eligible = false;

    /** Last diagnosis payload (rendered when present). */
    public ?array $diagnosis = null;

    /** Indicates we already attempted diagnosis (avoids re-running on re-render). */
    public bool $diagnosed = false;

    public string $errorMessage = '';

    public function mount(string $entityType, string $entityId): void
    {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->eligible = $this->resolveEligibility();
    }

    public function diagnose(): void
    {
        if (! $this->eligible) {
            return;
        }

        $this->errorMessage = '';
        $this->diagnosed = true;

        try {
            $this->diagnosis = match ($this->entityType) {
                'experiment' => $this->diagnoseExperiment(),
                'project' => $this->diagnoseProject(),
                'agent' => $this->diagnoseAgent(),
                'skill' => $this->diagnoseSkill(),
                'crew' => $this->diagnoseCrew(),
                'workflow' => $this->diagnoseWorkflow(),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('FixWithAssistant: diagnose failed', [
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'error' => $e->getMessage(),
            ]);
            $this->errorMessage = 'We could not diagnose this issue right now. Please try again or open the assistant manually.';
        }
    }

    /** @return array<string, mixed>|null */
    private function diagnoseExperiment(): ?array
    {
        $tool = app(ExperimentDiagnoseTool::class);
        $response = $tool->handle(new Request([
            'experiment_id' => $this->entityId,
            'locale' => app()->getLocale(),
        ]));

        $payload = json_decode((string) $response->content(), true);

        if ($response->isError()) {
            $this->errorMessage = is_array($payload) && isset($payload['error']['message'])
                ? (string) $payload['error']['message']
                : 'Diagnosis failed.';

            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Lightweight project diagnosis. Project failures are state-driven (paused
     * for budget, archived) rather than exception-driven, so we don't need
     * ErrorTranslator — a small switch on status produces the right summary
     * and recommended actions.
     *
     * @return array<string, mixed>|null
     */
    private function diagnoseProject(): ?array
    {
        $project = Project::find($this->entityId);
        if (! $project) {
            return null;
        }

        $isBg = str_starts_with(app()->getLocale(), 'bg');

        if ($project->status === ProjectStatus::Paused) {
            return [
                'project_id' => $project->id,
                'root_cause' => 'project_paused',
                'summary' => $isBg
                    ? 'Проектът е на пауза — планираните runs са спрени, докато не го стартираш отново.'
                    : 'This project is paused — scheduled runs are blocked until you resume it.',
                'evidence' => [
                    ['kind' => 'project_status', 'status' => $project->status->value],
                ],
                'recommended_actions' => [
                    [
                        'kind' => 'route',
                        'label' => $isBg ? 'Отвори проекта' : 'Open project',
                        'target' => 'projects.show',
                        'tier' => 'safe',
                        'icon' => 'fa-arrow-up-right-from-square',
                        'params' => ['project' => $project->id],
                    ],
                    [
                        'kind' => 'route',
                        'label' => $isBg ? 'Зареди кредити' : 'Top up credits',
                        'target' => 'billing',
                        'tier' => 'config',
                        'icon' => 'fa-credit-card',
                    ],
                ],
                'confidence' => 0.85,
                'retryable' => true,
            ];
        }

        if ($project->status === ProjectStatus::Archived) {
            return [
                'project_id' => $project->id,
                'root_cause' => 'project_archived',
                'summary' => $isBg
                    ? 'Проектът е архивиран — само за четене, не може да се реактивира.'
                    : 'This project is archived — read-only and cannot be reactivated.',
                'evidence' => [
                    ['kind' => 'project_status', 'status' => $project->status->value],
                ],
                'recommended_actions' => [],
                'confidence' => 1.0,
                'retryable' => false,
            ];
        }

        return null;
    }

    /**
     * Workflow diagnosis: workflows materialize as experiments at run time,
     * so the highest-signal diagnosis is "the latest failed experiment that
     * used this workflow." Delegates to ExperimentDiagnoseTool to inherit
     * the composite root-cause detection (stage error + circuit breaker +
     * worklog) without duplicating that pipeline.
     *
     * @return array<string, mixed>|null
     */
    private function diagnoseWorkflow(): ?array
    {
        $workflow = Workflow::find($this->entityId);
        if (! $workflow) {
            return null;
        }

        $windowDays = (int) config('self-service.diagnose.failure_window_days', 7);

        $latestFailedExperiment = Experiment::query()
            ->where('team_id', $workflow->team_id)
            ->where('workflow_id', $workflow->id)
            ->whereIn('status', [
                ExperimentStatus::ScoringFailed,
                ExperimentStatus::PlanningFailed,
                ExperimentStatus::BuildingFailed,
                ExperimentStatus::ExecutionFailed,
            ])
            ->where('updated_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('updated_at')
            ->first();

        if (! $latestFailedExperiment) {
            return null;
        }

        // Reuse the experiment diagnosis pipeline so workflow diagnosis
        // surfaces exactly the same actions and evidence the customer
        // would see if they navigated to the experiment directly.
        $tool = app(ExperimentDiagnoseTool::class);
        $response = $tool->handle(new Request([
            'experiment_id' => $latestFailedExperiment->id,
            'locale' => app()->getLocale(),
        ]));

        if ($response->isError()) {
            return null;
        }

        $payload = json_decode((string) $response->content(), true);
        if (! is_array($payload)) {
            return null;
        }

        // Reframe the experiment diagnosis as a workflow-rooted summary so the
        // user understands they're seeing the latest run of this workflow.
        $payload['workflow_id'] = $workflow->id;
        $payload['root_experiment_id'] = $latestFailedExperiment->id;

        return $payload;
    }

    /**
     * Crew diagnosis: find the latest failed task across the crew's recent
     * executions and route its error_message through ErrorTranslator. Crews
     * are multi-task so we surface one failure at a time — the most recent
     * one — and let the customer drill into the rest via the Executions tab.
     *
     * @return array<string, mixed>|null
     */
    private function diagnoseCrew(): ?array
    {
        $crew = Crew::find($this->entityId);
        if (! $crew) {
            return null;
        }

        $latestFailedTask = CrewTaskExecution::query()
            ->whereHas('crewExecution', fn ($q) => $q->where('crew_id', $crew->id)
                ->where('team_id', $crew->team_id))
            ->whereIn('status', [CrewTaskStatus::Failed, CrewTaskStatus::QaFailed])
            ->where('created_at', '>=', now()->subDays((int) config('self-service.diagnose.failure_window_days', 7)))
            ->orderByDesc('created_at')
            ->first();

        if (! $latestFailedTask) {
            return null;
        }

        $isBg = str_starts_with(app()->getLocale(), 'bg');
        $errorMessage = (string) ($latestFailedTask->error_message ?? '');

        if ($errorMessage === '') {
            return [
                'crew_id' => $crew->id,
                'root_cause' => 'crew_task_failure_no_message',
                'summary' => $isBg
                    ? 'Една от задачите в crew-а се провали без записано съобщение. Прегледай Executions таб-а за повече детайли.'
                    : 'A crew task failed with no recorded error message. Review the Executions tab for context.',
                'evidence' => [
                    [
                        'kind' => 'crew_task',
                        'task_id' => $latestFailedTask->id,
                        'title' => $latestFailedTask->title,
                        'status' => $latestFailedTask->status->value,
                        'attempt_number' => $latestFailedTask->attempt_number,
                        'created_at' => $latestFailedTask->created_at?->toIso8601String(),
                    ],
                ],
                'recommended_actions' => [],
                'confidence' => 0.4,
                'retryable' => false,
            ];
        }

        $translation = app(ErrorTranslator::class)->translate(
            technicalMessage: $errorMessage,
            locale: app()->getLocale(),
            placeholders: [
                'team_id' => (string) $crew->team_id,
                'crew_id' => $crew->id,
            ],
        );

        return [
            'crew_id' => $crew->id,
            'root_cause' => $translation->matched ? $translation->code : 'crew_unknown_failure',
            'summary' => $translation->message,
            'evidence' => [
                [
                    'kind' => 'crew_task',
                    'task_id' => $latestFailedTask->id,
                    'title' => $latestFailedTask->title,
                    'status' => $latestFailedTask->status->value,
                    'attempt_number' => $latestFailedTask->attempt_number,
                    'created_at' => $latestFailedTask->created_at?->toIso8601String(),
                ],
            ],
            'recommended_actions' => array_map(fn ($a) => $a->toArray(), $translation->actions),
            'confidence' => $translation->matched ? 0.85 : 0.4,
            'retryable' => $translation->retryable,
            'mcp_error_code' => $translation->mcpErrorCode->value,
            'matched_dictionary' => $translation->matched,
        ];
    }

    /**
     * Skill diagnosis routes the latest failed SkillExecution's error_message
     * through ErrorTranslator so the customer gets the same dictionary-driven
     * recovery options as for experiment failures, scoped to skill context.
     *
     * @return array<string, mixed>|null
     */
    private function diagnoseSkill(): ?array
    {
        $skill = Skill::find($this->entityId);
        if (! $skill) {
            return null;
        }

        $latestFailure = SkillExecution::withoutGlobalScopes()
            ->where('team_id', $skill->team_id)
            ->where('skill_id', $skill->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays((int) config('self-service.diagnose.failure_window_days', 7)))
            ->orderByDesc('created_at')
            ->first();

        if (! $latestFailure) {
            return null;
        }

        $isBg = str_starts_with(app()->getLocale(), 'bg');
        $errorMessage = (string) ($latestFailure->error_message ?? '');

        if ($errorMessage === '') {
            return [
                'skill_id' => $skill->id,
                'root_cause' => 'skill_failure_no_message',
                'summary' => $isBg
                    ? 'Последното изпълнение на скила е failed без записано съобщение. Прегледай Executions таб-а за повече детайли.'
                    : 'The latest skill execution failed with no recorded error message. Review the Executions tab for context.',
                'evidence' => [
                    [
                        'kind' => 'skill_execution',
                        'execution_id' => $latestFailure->id,
                        'created_at' => $latestFailure->created_at?->toIso8601String(),
                    ],
                ],
                'recommended_actions' => [],
                'confidence' => 0.4,
                'retryable' => false,
            ];
        }

        $translation = app(ErrorTranslator::class)->translate(
            technicalMessage: $errorMessage,
            locale: app()->getLocale(),
            placeholders: [
                'team_id' => (string) $skill->team_id,
                'skill_id' => $skill->id,
            ],
        );

        return [
            'skill_id' => $skill->id,
            'root_cause' => $translation->matched ? $translation->code : 'skill_unknown_failure',
            'summary' => $translation->message,
            'evidence' => [
                [
                    'kind' => 'skill_execution',
                    'execution_id' => $latestFailure->id,
                    'duration_ms' => $latestFailure->duration_ms,
                    'created_at' => $latestFailure->created_at?->toIso8601String(),
                ],
            ],
            'recommended_actions' => array_map(fn ($a) => $a->toArray(), $translation->actions),
            'confidence' => $translation->matched ? 0.85 : 0.4,
            'retryable' => $translation->retryable,
            'mcp_error_code' => $translation->mcpErrorCode->value,
            'matched_dictionary' => $translation->matched,
        ];
    }

    /**
     * Lightweight agent diagnosis. Surfaces circuit-breaker state and
     * disabled-status with relevant recovery suggestions.
     *
     * @return array<string, mixed>|null
     */
    private function diagnoseAgent(): ?array
    {
        $agent = Agent::with('circuitBreakerState')->find($this->entityId);
        if (! $agent) {
            return null;
        }

        $isBg = str_starts_with(app()->getLocale(), 'bg');
        $cb = $agent->circuitBreakerState;

        if ($cb !== null && in_array($cb->state, ['open', 'half_open'], true)) {
            return [
                'agent_id' => $agent->id,
                'root_cause' => 'circuit_breaker_'.$cb->state,
                'summary' => $isBg
                    ? sprintf(
                        'Circuit breaker за този агент е %s след %d поредни грешки. Новите runs са блокирани докато cooldown-ът изтече.',
                        $cb->state === 'open' ? 'отворен' : 'полу-отворен',
                        (int) ($cb->failure_count ?? 0),
                    )
                    : sprintf(
                        'Circuit breaker for this agent is %s after %d consecutive failures. New runs are blocked until the cooldown expires.',
                        $cb->state,
                        (int) ($cb->failure_count ?? 0),
                    ),
                'evidence' => [
                    [
                        'kind' => 'circuit_breaker',
                        'state' => $cb->state,
                        'failure_count' => $cb->failure_count,
                        'opened_at' => $cb->opened_at?->toIso8601String(),
                    ],
                ],
                'recommended_actions' => [
                    [
                        'kind' => 'assistant',
                        'label' => $isBg ? 'Питай асистента' : 'Investigate with assistant',
                        'target' => sprintf(
                            'Agent %s has its circuit breaker %s after %d failures. Investigate the recent failed experiments and recommend a fix.',
                            $agent->id,
                            $cb->state,
                            (int) ($cb->failure_count ?? 0),
                        ),
                        'tier' => 'safe',
                        'icon' => 'fa-magnifying-glass',
                    ],
                    [
                        'kind' => 'route',
                        'label' => $isBg ? 'Настройки на агента' : 'Agent settings',
                        'target' => 'agents.show',
                        'tier' => 'config',
                        'icon' => 'fa-gear',
                        'params' => ['agent' => $agent->id],
                    ],
                ],
                'confidence' => 0.9,
                'retryable' => true,
            ];
        }

        if (method_exists($agent, 'isDisabled') && $agent->isDisabled()) {
            return [
                'agent_id' => $agent->id,
                'root_cause' => 'agent_disabled',
                'summary' => $isBg
                    ? 'Агентът е изключен и няма да поеме нови задачи. Включи го отново когато си готов.'
                    : 'This agent is disabled and will not pick up new work. Re-enable it when ready.',
                'evidence' => [
                    ['kind' => 'agent_status', 'status' => 'disabled'],
                ],
                'recommended_actions' => [
                    [
                        'kind' => 'route',
                        'label' => $isBg ? 'Отвори агента' : 'Open agent',
                        'target' => 'agents.show',
                        'tier' => 'safe',
                        'icon' => 'fa-arrow-up-right-from-square',
                        'params' => ['agent' => $agent->id],
                    ],
                ],
                'confidence' => 1.0,
                'retryable' => false,
            ];
        }

        return null;
    }

    /**
     * Open the assistant panel with the given prompt prefilled. Triggered by
     * an action of kind=assistant (e.g. "Ask assistant to investigate").
     */
    public function askAssistant(string $prompt): void
    {
        // The assistant panel listens for 'open-assistant' on window (browser event)
        // and pre-fills the input.
        $this->dispatch('open-assistant', message: $prompt);
    }

    /**
     * Direct execution path for safe-tier `tool` recovery actions, bypassing
     * the assistant for one-click recovery (P1 inline recovery work).
     *
     * Only tool names on the explicit allowlist are honored, and only safe-tier
     * (non-config, non-destructive) actions reach this method from the UI.
     * config / destructive tier still funnel through the assistant for the
     * existing role-tier gating.
     *
     * @param  array<string, mixed>  $params
     */
    public function executeRecoveryAction(string $toolName, array $params = []): void
    {
        if (! $this->eligible) {
            return;
        }

        // Defense-in-depth: explicit allowlist of safe-tier MCP tools that
        // self-service customers can run from the diagnose card. Adding to this
        // list MUST be done together with the dictionary 'tier' => 'safe'.
        $allowed = [
            'experiment_retry' => ExperimentRetryTool::class,
            'experiment_resume' => ExperimentResumeTool::class,
            'experiment_resume_from_checkpoint' => ExperimentResumeFromCheckpointTool::class,
        ];

        if (! isset($allowed[$toolName])) {
            $this->errorMessage = 'This action is not allowlisted for one-click execution.';

            return;
        }

        // The recommended action only operates on the entity in scope.
        // Forcing experiment_id to the bound entity prevents replay against
        // an unrelated experiment if the params payload were tampered with.
        if (isset($params['experiment_id'])) {
            $params['experiment_id'] = $this->entityId;
        }

        try {
            // Bind mcp.team_id so the underlying tool's tenant resolution
            // works without relying on auth() during a Livewire request.
            $teamId = auth()->user()?->current_team_id;
            if ($teamId !== null) {
                app()->instance('mcp.team_id', $teamId);
            }

            /** @var Tool $tool */
            $tool = app($allowed[$toolName]);
            $response = $tool->handle(new Request($params));

            if ($response->isError()) {
                $payload = json_decode((string) $response->content(), true);
                $this->errorMessage = is_array($payload) && isset($payload['error']['message'])
                    ? (string) $payload['error']['message']
                    : 'Recovery action failed.';

                return;
            }

            // Success — clear the diagnosis card and notify the parent page.
            $this->dispatch('notify', message: 'Recovery action executed.', type: 'success');
            $this->dispatch('experiment-recovered', toolName: $toolName);
            $this->diagnosis = null;
            $this->diagnosed = false;
            $this->errorMessage = '';

            // Re-evaluate eligibility — the experiment may no longer be failed.
            $this->eligible = $this->resolveEligibility();
        } catch (\Throwable $e) {
            Log::warning('FixWithAssistant: recovery action failed', [
                'tool' => $toolName,
                'entity_id' => $this->entityId,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);
            // Surface the exception class to help support agents triage; the
            // raw message can leak internal details so we keep it server-side.
            $this->errorMessage = 'Recovery action threw '.class_basename($e).'.';
        }
    }

    public function render(): View
    {
        return view('livewire.shared.fix-with-assistant');
    }

    /**
     * Decide whether to surface the component for the given entity. A
     * mismatched/missing entity simply renders nothing.
     */
    private function resolveEligibility(): bool
    {
        if ($this->entityId === '') {
            return false;
        }

        return match ($this->entityType) {
            'experiment' => $this->experimentEligible(),
            'project' => $this->projectEligible(),
            'agent' => $this->agentEligible(),
            'skill' => $this->skillEligible(),
            'crew' => $this->crewEligible(),
            'workflow' => $this->workflowEligible(),
            default => false,
        };
    }

    private function workflowEligible(): bool
    {
        $workflow = Workflow::find($this->entityId);
        if (! $workflow) {
            return false;
        }

        $windowDays = (int) config('self-service.diagnose.failure_window_days', 7);

        return Experiment::query()
            ->where('team_id', $workflow->team_id)
            ->where('workflow_id', $workflow->id)
            ->whereIn('status', [
                ExperimentStatus::ScoringFailed,
                ExperimentStatus::PlanningFailed,
                ExperimentStatus::BuildingFailed,
                ExperimentStatus::ExecutionFailed,
            ])
            ->where('updated_at', '>=', now()->subDays($windowDays))
            ->exists();
    }

    private function crewEligible(): bool
    {
        $crew = Crew::find($this->entityId);
        if (! $crew) {
            return false;
        }

        return CrewTaskExecution::query()
            ->whereHas('crewExecution', fn ($q) => $q->where('crew_id', $crew->id)
                ->where('team_id', $crew->team_id))
            ->whereIn('status', [CrewTaskStatus::Failed, CrewTaskStatus::QaFailed])
            ->where('created_at', '>=', now()->subDays((int) config('self-service.diagnose.failure_window_days', 7)))
            ->exists();
    }

    private function skillEligible(): bool
    {
        $skill = Skill::find($this->entityId);
        if (! $skill) {
            return false;
        }

        return SkillExecution::withoutGlobalScopes()
            ->where('team_id', $skill->team_id)
            ->where('skill_id', $skill->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays((int) config('self-service.diagnose.failure_window_days', 7)))
            ->exists();
    }

    private function experimentEligible(): bool
    {
        $experiment = Experiment::find($this->entityId);
        if (! $experiment) {
            return false;
        }
        $status = $experiment->status;

        return $status->isFailed() || $status->value === 'paused';
    }

    private function projectEligible(): bool
    {
        $project = Project::find($this->entityId);
        if (! $project) {
            return false;
        }

        return in_array($project->status, [ProjectStatus::Paused, ProjectStatus::Archived], true);
    }

    private function agentEligible(): bool
    {
        $agent = Agent::with('circuitBreakerState')->find($this->entityId);
        if (! $agent) {
            return false;
        }

        $cb = $agent->circuitBreakerState;
        if ($cb !== null && in_array($cb->state, ['open', 'half_open'], true)) {
            return true;
        }

        return method_exists($agent, 'isDisabled') && $agent->isDisabled();
    }
}
