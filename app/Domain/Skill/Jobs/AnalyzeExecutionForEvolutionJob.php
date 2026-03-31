<?php

namespace App\Domain\Skill\Jobs;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Analyzes a completed agent execution and auto-generates EvolutionProposal
 * records for skills that show signs of degradation or improvement opportunities.
 *
 * Dispatched by DispatchEvolutionAnalysisListener after AgentExecuted fires.
 */
class AnalyzeExecutionForEvolutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        public readonly string $executionId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $execution = AgentExecution::with('agent')->find($this->executionId);
        if (! $execution) {
            return;
        }

        $team = Team::withoutGlobalScopes()->find($execution->agent?->team_id);
        $enabled = $team?->settings['autonomous_evolution_enabled']
            ?? config('skills.autonomous_evolution.enabled', true);
        if (! $enabled) {
            return;
        }

        $skillsExecuted = $execution->skills_executed ?? [];
        if (empty($skillsExecuted)) {
            return;
        }

        $maxProposals = config('skills.autonomous_evolution.max_proposals_per_execution', 3);
        $minConfidence = config('skills.autonomous_evolution.min_confidence', 0.6);
        $proposalsCreated = 0;

        foreach (array_slice($skillsExecuted, 0, $maxProposals) as $skillRef) {
            $skillId = is_array($skillRef) ? ($skillRef['id'] ?? null) : $skillRef;
            if (! $skillId) {
                continue;
            }

            $skill = Skill::find($skillId);
            if (! $skill) {
                continue;
            }

            // Only analyze skills with enough execution history
            if ($skill->applied_count < config('skills.degradation.min_sample_size', 10)) {
                continue;
            }

            // Skip if a duplicate pending proposal already exists for this trigger type
            $alreadyPending = EvolutionProposal::query()
                ->where('skill_id', $skill->id)
                ->where('status', EvolutionProposalStatus::Pending)
                ->where('trigger', 'post_execution')
                ->exists();

            if ($alreadyPending) {
                continue;
            }

            $proposal = $this->analyzeWithLlm($execution, $skill, $minConfidence);

            if ($proposal && $proposalsCreated < $maxProposals) {
                EvolutionProposal::create([
                    'team_id' => $execution->team_id,
                    'agent_id' => $execution->agent_id,
                    'skill_id' => $skill->id,
                    'execution_id' => $execution->id,
                    'trigger' => 'post_execution',
                    'status' => EvolutionProposalStatus::Pending,
                    'analysis' => $proposal['analysis'],
                    'proposed_changes' => $proposal['proposed_changes'],
                    'reasoning' => $proposal['reasoning'],
                    'confidence_score' => $proposal['confidence_score'],
                ]);

                $proposalsCreated++;

                // Detect fallback: agent bypassed the skill effectively during this execution
                $evolutionType = $proposal['proposed_changes']['type'] ?? '';
                if ($evolutionType === 'captured') {
                    Skill::withoutGlobalScopes()->where('id', $skill->id)->increment('fallback_count');
                }
            }
        }
    }

    /**
     * Call the configured LLM to analyze whether the skill warrants an evolution proposal.
     *
     * @return array{analysis: string, proposed_changes: array<string, mixed>, reasoning: string, confidence_score: float}|null
     */
    private function analyzeWithLlm(AgentExecution $execution, Skill $skill, float $minConfidence): ?array
    {
        $apiKey = config('prism.providers.anthropic.api_key');
        if (empty($apiKey)) {
            return null;
        }

        $executionSummary = sprintf(
            "Status: %s\nQuality score: %s\nDuration: %dms\nTool calls: %d",
            $execution->status,
            $execution->quality_score ?? 'N/A',
            $execution->duration_ms ?? 0,
            $execution->tool_calls_count ?? 0,
        );

        $skillSummary = sprintf(
            "Name: %s\nDescription: %s\nType: %s\nReliability: %.0f%%\nQuality rate: %.0f%%\nApplied: %d times",
            $skill->name,
            $skill->description ?? 'N/A',
            $skill->type->value ?? 'unknown',
            $skill->reliability_rate * 100,
            $skill->quality_rate * 100,
            $skill->applied_count,
        );

        $prompt = <<<PROMPT
You are analyzing an AI agent execution to determine if any skills used during the execution should be improved.

## Execution Summary
{$executionSummary}

## Skill Being Analyzed
{$skillSummary}

Based on this data, determine if this skill should be evolved. Respond with ONLY valid JSON in this exact format:
{
  "should_evolve": true,
  "evolution_type": "fix",
  "analysis": "Brief description of what needs improvement",
  "proposed_changes": {"type": "fix", "areas": ["system_prompt", "input_schema"]},
  "reasoning": "Why this evolution is needed",
  "confidence_score": 0.75
}

evolution_type must be one of: fix, derived, captured
confidence_score must be between 0.0 and 1.0
If should_evolve is false, still return the JSON with confidence_score < 0.5
PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => config('skills.autonomous_evolution.model', 'claude-haiku-4-5-20251001'),
                'max_tokens' => 512,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])->throw()->json();

            $content = $response['content'][0]['text'] ?? '';

            // Extract JSON block from the response text
            preg_match('/\{.*\}/s', $content, $matches);
            if (empty($matches[0])) {
                return null;
            }

            /** @var array<string, mixed>|null $result */
            $result = json_decode($matches[0], true);
            if (! $result || ! isset($result['should_evolve'])) {
                return null;
            }

            if (! $result['should_evolve']) {
                return null;
            }

            $confidence = (float) ($result['confidence_score'] ?? 0);
            if ($confidence < $minConfidence) {
                return null;
            }

            return [
                'analysis' => (string) ($result['analysis'] ?? 'Automated post-execution analysis.'),
                'proposed_changes' => (array) ($result['proposed_changes'] ?? ['type' => $result['evolution_type'] ?? 'fix']),
                'reasoning' => (string) ($result['reasoning'] ?? ''),
                'confidence_score' => $confidence,
            ];
        } catch (\Throwable $e) {
            Log::warning('AnalyzeExecutionForEvolutionJob: LLM analysis failed', [
                'execution_id' => $this->executionId,
                'skill_id' => $skill->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
