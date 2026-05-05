<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Services\ReasoningBankService;
use App\Domain\Memory\Services\MemoryContextInjector;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class RunPlanningStage extends BaseStageJob
{
    public function __construct(string $experimentId, ?string $teamId = null)
    {
        parent::__construct($experimentId, $teamId);
        $this->onQueue('ai-calls');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Planning;
    }

    protected function stageType(): StageType
    {
        return StageType::Planning;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $gateway = app(AiGatewayInterface::class);
        $transition = app(TransitionExperimentAction::class);
        $llm = $this->resolvePipelineLlm($experiment);

        // Gather context from prior stages
        $scoringStage = $experiment->stages()
            ->where('stage', StageType::Scoring)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $scoringOutput = $scoringStage?->output_snapshot ?? [];

        // Check for rejection feedback from previous cycle
        $rejectionFeedback = $experiment->stateTransitions()
            ->where('to_state', ExperimentStatus::Rejected->value)
            ->latest('created_at')
            ->value('metadata');

        $contextParts = [
            "Title: {$experiment->title}",
            "Thesis: {$experiment->thesis}",
            "Track: {$experiment->track->value}",
            "Iteration: {$experiment->current_iteration}",
            'Scoring output: '.json_encode($scoringOutput, JSON_UNESCAPED_UNICODE),
        ];

        // Inject relevant memories from past experiments
        $projectId = ProjectRun::where('experiment_id', $experiment->id)->value('project_id');
        if ($projectId) {
            $injector = app(MemoryContextInjector::class);
            $memory = $injector->buildContext(
                agentId: $experiment->agent_id,
                input: $experiment->thesis ?? $experiment->title,
                projectId: $projectId,
                teamId: $experiment->team_id,
            );
            if ($memory) {
                $contextParts[] = "--- Past Learnings ---\n{$memory}";
            }
        }

        // Inject ReasoningBank hints: top-3 similar past strategies
        try {
            $hints = app(ReasoningBankService::class)->fetchHints(
                goalText: $experiment->thesis ?? $experiment->title,
                teamId: $experiment->team_id,
                k: 3,
            );
            if ($hints->isNotEmpty()) {
                $hintText = $hints->map(fn ($h, $i) => 'Strategy '.($i + 1).': '.$h->outcome_summary."\nTools: ".
                    collect($h->tool_sequence)->pluck('tool_name')->filter()->implode(' → '),
                )->implode("\n\n");
                $contextParts[] = "--- Past Strategy Hints (similar goals) ---\n{$hintText}";
            }
        } catch (\Throwable) {
            // Non-fatal: skip hints if reasoning bank is unavailable
        }

        if ($rejectionFeedback) {
            $contextParts[] = 'Previous rejection feedback: '.json_encode($rejectionFeedback, JSON_UNESCAPED_UNICODE);
        }

        // Include dependency context from predecessor projects
        $dependencyContext = $experiment->constraints['dependency_context'] ?? [];
        if (! empty($dependencyContext)) {
            $contextParts[] = "\n--- Context from predecessor projects ---";
            foreach ($dependencyContext as $alias => $data) {
                $contextParts[] = "[{$alias}] Project: {$data['project_title']} (Run #{$data['run_number']})";
                foreach ($data['artifacts'] ?? [] as $artifact) {
                    $contextParts[] = "Artifact [{$artifact['type']}] {$artifact['name']}:\n{$artifact['content']}";
                }
                if (! empty($data['stage_outputs'])) {
                    $contextParts[] = 'Stage outputs: '.json_encode($data['stage_outputs'], JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // Inject signal context so AI knows who to respond to
        $run = ProjectRun::where('experiment_id', $experiment->id)->first();
        if ($run?->signal_id) {
            $signal = Signal::withoutGlobalScopes()->find($run->signal_id);
            if ($signal) {
                $payload = $signal->payload ?? [];
                $contextParts[] = "--- Triggering Signal ---\nSource: {$signal->source_type}\nFrom: ".($payload['from'] ?? $signal->source_identifier)."\nSubject: ".($payload['subject'] ?? $payload['title'] ?? 'N/A')."\nBody: ".mb_substr($payload['body'] ?? $payload['content'] ?? '', 0, 1000);
            }
        }

        // Inject allowed outbound channels from project config
        $project = $run?->project_id ? Project::withoutGlobalScopes()->find($run->project_id) : null;
        $deliveryConfig = $project?->delivery_config ?? [];
        $allowedChannels = $deliveryConfig['allowed_outbound_channels'] ?? null;
        $channelConstraint = '';
        if ($allowedChannels && count($allowedChannels) > 0) {
            $channelConstraint = ' IMPORTANT: Only use these outbound channels: '.implode(', ', $allowedChannels).'. Do NOT propose channels outside this list.';
        }

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: 'You are an experiment planning agent. Create an execution plan. If context from predecessor projects is provided, incorporate their findings and artifacts into your plan. If a triggering signal is provided, the plan should process and respond to it. Return ONLY a valid JSON object (no markdown, no code fences) with: plan_summary (string, max 3 sentences), artifacts_to_build (array of {type, name, description}), outbound_channels (array of {channel, target_description}), success_metrics (array of max 5 strings), estimated_timeline_hours (int). Keep compact.'.$channelConstraint."\n\nCRITICAL: Each artifact in artifacts_to_build must be SMALL and ATOMIC — completable in a single focused LLM call producing ~200-400 words of output. Split large deliverables into multiple focused artifacts (e.g. 'hero_section', 'features_section', 'cta_section' instead of 'full_landing_page'). Prefer 5-15 small artifacts over 2-3 large ones. Never create an artifact whose description implies generating a complete multi-section document in one step.",
            userPrompt: implode("\n\n", $contextParts),
            maxTokens: 2048,
            userId: $experiment->user_id,
            experimentId: $experiment->id,
            experimentStageId: $stage->id,
            purpose: 'planning',
            temperature: 0.5,
            teamId: $experiment->team_id,
        );

        $response = $gateway->complete($request);

        $parsedOutput = $this->parseJsonResponse($response);

        if (! is_array($parsedOutput) || empty($parsedOutput['plan_summary'])) {
            throw new \RuntimeException(
                'Planning stage: LLM returned invalid plan (missing plan_summary). '
                .'Raw response: '.substr($response->content ?? '', 0, 500),
            );
        }

        // Mark stage Completed BEFORE transitioning — the TransitionPrerequisiteValidator
        // checks for a completed planning stage when entering Building. If we leave it as
        // Running and call transition first, the validator rejects the transition.
        $stage->update([
            'output_snapshot' => $parsedOutput,
            'status' => StageStatus::Completed,
            'completed_at' => now(),
        ]);

        $transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Building,
            reason: 'Plan generated',
        );
    }
}
